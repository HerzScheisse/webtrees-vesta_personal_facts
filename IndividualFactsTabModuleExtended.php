<?php

namespace Cissee\Webtrees\Module\PersonalFacts;

use Cissee\Webtrees\Hook\HookInterfaces\IndividualFactsTabExtenderInterface;
use Cissee\Webtrees\Hook\HookInterfaces\IndividualFactsTabExtenderUtils;
use Cissee\WebtreesExt\Module\IndividualFactsTabModule_2x;
use Cissee\WebtreesExt\ToggleableFactsCategory;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Http\Controllers\Admin\ModuleController;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleTabInterface;
use Fisharebest\Webtrees\Services\ClipboardService;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Services\TreeService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionObject;
use Vesta\Hook\HookInterfaces\FunctionsPlaceInterface;
use Vesta\Hook\HookInterfaces\FunctionsPlaceUtils;
use Vesta\Model\GenericViewElement;
use Vesta\VestaAdminController;
use Vesta\VestaModuleTrait;
use function app;
use function redirect;
use function route;
use function view;

class IndividualFactsTabModuleExtended extends IndividualFactsTabModule_2x implements ModuleCustomInterface, ModuleConfigInterface, ModuleTabInterface {

  use VestaModuleTrait;
  use IndividualFactsTabModuleTrait;

  public function __construct(ModuleService $module_service, ClipboardService $clipboard_service) {
    parent::__construct($module_service, $clipboard_service);
    $this->setFunctionsPrintFacts(new FunctionsPrintFactsWithHooks(new FunctionsPrintWithHooks($this), $this));
  }

  protected function onBoot(): void {
    //we do not want to use the original name 'modules/personal_facts/tab' here, so we use our own namespace
    $this->setViewName($this->name() . '::tab');
  }

  public function customModuleAuthorName(): string {
    return 'Richard Cissée';
  }

  public function customModuleVersion(): string {
    return '2.0.0-beta.5.1';
  }

  public function customModuleLatestVersionUrl(): string {
    return 'https://cissee.de';
  }

  public function customModuleSupportUrl(): string {
    return 'https://cissee.de';
  }

  public function description(): string {
    return $this->getShortDescription();
  }

  /**
   * Where does this module store its resources
   *
   * @return string
   */
  public function resourcesFolder(): string {
    return __DIR__ . '/resources/';
  }

  public function tabTitle(): string {
    return $this->getTabTitle(I18N::translate('Facts and events'));
  }

  protected function getOutputBeforeTab(Individual $person) {
    $pre = '<link href="' . $this->assetUrl('css/style.css') . '" type="text/css" rel="stylesheet" />';

    $a1 = array(new GenericViewElement($pre, ''));

    $a2 = IndividualFactsTabExtenderUtils::accessibleModules($this, $person->tree(), Auth::user())
            ->map(function (IndividualFactsTabExtenderInterface $module) use ($person) {
              return $module->hFactsTabGetOutputBeforeTab($person);
            })
            ->toArray();

    return GenericViewElement::implode(array_merge($a1, $a2));
  }

  protected function getOutputAfterTab(Individual $person) {
    $a = IndividualFactsTabExtenderUtils::accessibleModules($this, $person->tree(), Auth::user())
            ->map(function (IndividualFactsTabExtenderInterface $module) use ($person) {
              return $module->hFactsTabGetOutputAfterTab($person);
            })
            ->toArray();

    return GenericViewElement::implode($a);
  }

  protected function additionalFacts(Individual $person) {
    $facts = array();
    $ret = IndividualFactsTabExtenderUtils::accessibleModules($this, $person->tree(), Auth::user())
            ->map(function (IndividualFactsTabExtenderInterface $module) use ($person) {
              return $module->hFactsTabGetAdditionalFacts($person);
            })
            ->toArray();

    foreach ($ret as $r) {
      foreach ($r as $rr) {
        $facts[] = $rr;
      }
    }
    return $facts;
  }

  protected function getToggleableFactsCategories($show_relatives_facts, $has_historical_facts) {
    $categories = [];

    /* [RC] note: this is problematic wrt asso events, which we still may want to show */
    if ($show_relatives_facts || (!$this->getPreference('ASSO_SEPARATE', '0') && $this->showAssociateFacts())) {
      $categories[] = new ToggleableFactsCategory(
              'show-relatives-facts-pfh', //cf FunctionsPrintFactsWithHooks.additionalStyleadds()!
              '.wt-relation-fact-pfh',
              I18N::translate('Events of close relatives'));
    }

    if ($this->getPreference('ASSO_SEPARATE', '0') && $this->showAssociateFacts()) {
      $categories[] = new ToggleableFactsCategory(
              'show-associate-facts-pfh', //cf FunctionsPrintFactsWithHooks.additionalStyleadds()!
              '.wt-associate-fact-pfh',
              I18N::translate('Facts and events of inverse associates'));
    } //if setting for separate checkbox isn't set: toggles via show-relatives-facts-pfh!

    if ($has_historical_facts) {
      $categories[] = new ToggleableFactsCategory(
              'show-historical-facts-pfh', //cf FunctionsPrintFactsWithHooks.additionalStyleadds()!
              '.wt-historic-fact-pfh',
              I18N::translate('Historical facts'));
    }

    return $categories;
  }

  //[RC] ADDED
  protected function getOutputInDescriptionBox(Individual $person) {
    return GenericViewElement::implode(IndividualFactsTabExtenderUtils::accessibleModules($this, $person->tree(), Auth::user())
                            ->map(function (IndividualFactsTabExtenderInterface $module) use ($person) {
                              return $module->hFactsTabGetOutputInDBox($person);
                            })
                            ->toArray());
  }

  //[RC] ADDED
  protected function getOutputAfterDescriptionBox(Individual $person) {
    return GenericViewElement::implode(IndividualFactsTabExtenderUtils::accessibleModules($this, $person->tree(), Auth::user())
                            ->map(function (IndividualFactsTabExtenderInterface $module) use ($person) {
                              return $module->hFactsTabGetOutputAfterDBox($person);
                            })
                            ->toArray());
  }

  protected function showAssociateFacts() {
    $restricted = $this->getPreference('ASSO_RESTRICTED', '0');

    if ($restricted) {
      //check if completely empty - in which case we may shortcut			
      $restrictedTo = preg_split("/[, ;:]+/", $this->getPreference('ASSO_RESTRICTED_INDI', 'CHR,BAPM'), -1, PREG_SPLIT_NO_EMPTY);
      if (sizeof($restrictedTo) === 0) {
        $restrictedTo = preg_split("/[, ;:]+/", $this->getPreference('ASSO_RESTRICTED_FAM', 'MARR'), -1, PREG_SPLIT_NO_EMPTY);
        if (sizeof($restrictedTo) === 0) {
          return false;
        }
      }
    }

    return true;
  }

  protected function associateFacts(Individual $person) {
    //shortcut?
    if (!$this->showAssociateFacts()) {
      return array();
    }

    return parent::associateFacts($person);
  }

  protected function filterAssociateFact(Fact $fact) {
    $restricted = (boolean) $this->getPreference('ASSO_RESTRICTED', '0');
    if ($restricted) {
      $parent = $fact->record();
      if ($parent instanceof Family) {
        $restrictedTo = preg_split("/[, ;:]+/", $this->getPreference('ASSO_RESTRICTED_FAM', 'MARR'), -1, PREG_SPLIT_NO_EMPTY);
        if (!in_array($fact->getTag(), $restrictedTo, true)) {
          return false;
        }
      } else {
        $restrictedTo = preg_split("/[, ;:]+/", $this->getPreference('ASSO_RESTRICTED_INDI', 'CHR,BAPM'), -1, PREG_SPLIT_NO_EMPTY);
        if (!in_array($fact->getTag(), $restrictedTo, true)) {
          return false;
        }
      }
    }

    return true;
  }

  //////////////////////////////////////////////////////////////////////////////
  
  private function title1(): string {
    return I18N::translate('Facts and Events Tab Location Data Providers');
  }
  
  private function description1(): string {
    return I18N::translate('Modules listed here are used (in the configured order) to determine map coordinates of places.');
  }
  
  private function title2(): string {
    return I18N::translate('Facts and Events Tab UI Element Providers');
  }
  
  private function description2(): string {
    return I18N::translate('Modules listed here may provide additional data for facts and events (displayed in the configured order).');
  }
  
  //hook management - generalize?
  //adapted from ModuleController (e.g. listFooters)
  public function getProviders1Action(): ResponseInterface {
    $modules = FunctionsPlaceUtils::modules($this, true);

    $controller = new VestaAdminController($this->name());
    return $controller->listHooks(
                    $modules,
                    FunctionsPlaceInterface::class,
                    $this->title1(),
                    $this->description1(),
                    true,
                    true);
  }
  
  public function getProviders2Action(): ResponseInterface {
    $modules = IndividualFactsTabExtenderUtils::modules($this, true);

    $controller = new VestaAdminController($this->name());
    return $controller->listHooks(
                    $modules,
                    IndividualFactsTabExtenderInterface::class,
                    $this->title2(),
                    $this->description2(),
                    true,
                    true);
  }

  public function postProviders1Action(ServerRequestInterface $request): ResponseInterface {
    $modules = FunctionsPlaceUtils::modules($this, true);

    $controller1 = new ModuleController($this->module_service, app(TreeService::class));
    $reflector = new ReflectionObject($controller1);

    //private!
    //$controller1->updateStatus($modules, $request);

    $method = $reflector->getMethod('updateStatus');
    $method->setAccessible(true);
    $method->invoke($controller1, $modules, $request);

    FunctionsPlaceUtils::updateOrder($this, $request);

    //private!
    //$controller1->updateAccessLevel($modules, FunctionsPlaceInterface::class, $request);

    $method = $reflector->getMethod('updateAccessLevel');
    $method->setAccessible(true);
    $method->invoke($controller1, $modules, FunctionsPlaceInterface::class, $request);

    $url = route('module', [
        'module' => $this->name(),
        'action' => 'Providers1'
    ]);

    return redirect($url);
  }
  
  public function postProviders2Action(ServerRequestInterface $request): ResponseInterface {
    $modules = IndividualFactsTabExtenderUtils::modules($this, true);

    $controller1 = new ModuleController($this->module_service, app(TreeService::class));
    $reflector = new ReflectionObject($controller1);

    //private!
    //$controller1->updateStatus($modules, $request);

    $method = $reflector->getMethod('updateStatus');
    $method->setAccessible(true);
    $method->invoke($controller1, $modules, $request);

    IndividualFactsTabExtenderUtils::updateOrder($this, $request);

    //private!
    //$controller1->updateAccessLevel($modules, IndividualFactsTabExtenderInterface::class, $request);

    $method = $reflector->getMethod('updateAccessLevel');
    $method->setAccessible(true);
    $method->invoke($controller1, $modules, IndividualFactsTabExtenderInterface::class, $request);

    $url = route('module', [
        'module' => $this->name(),
        'action' => 'Providers2'
    ]);

    return redirect($url);
  }

  protected function editConfigBeforeFaq() {
    $modules1 = FunctionsPlaceUtils::modules($this, true);

    $url1 = route('module', [
        'module' => $this->name(),
        'action' => 'Providers1'
    ]);
    
    $modules2 = IndividualFactsTabExtenderUtils::modules($this, true);

    $url2 = route('module', [
        'module' => $this->name(),
        'action' => 'Providers2'
    ]);

    //cf control-panel.phtml
    ?>
    <div class="card-body">
        <div class="row">
            <div class="col-sm-9">
                <ul class="fa-ul">
                    <li>
                        <span class="fa-li"><?= view('icons/block') ?></span>
                        <a href="<?= e($url1) ?>">
                            <?= $this->title1() ?>
                        </a>
                        <?= view('components/badge', ['count' => $modules1->count()]) ?>
                        <p class="small text-muted">
                          <?= $this->description1() ?>
                        </p>
                    </li>
                    <li>
                        <span class="fa-li"><?= view('icons/block') ?></span>
                        <a href="<?= e($url2) ?>">
                            <?= $this->title2() ?>
                        </a>
                        <?= view('components/badge', ['count' => $modules2->count()]) ?>
                        <p class="small text-muted">
                          <?= $this->description2() ?>
                        </p>
                    </li>
                </ul>
            </div>
        </div>
    </div>		

    <?php
  }

}
