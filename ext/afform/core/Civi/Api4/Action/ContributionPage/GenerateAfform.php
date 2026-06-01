<?php

namespace Civi\Api4\Action\ContributionPage;

use Civi\Afform\ContributionPageAfformProvider;

/**
 * Generate and persist a FormBuilder form for a ContributionPage.
 *
 * Writes the form to the site-local afform path ([civicrm.files]/ang/) so it
 * appears as a saved, editable form in FormBuilder.
 *
 * Customization detection: the generated .aff.json stores autogen_layout_hash
 * (md5 of the layout at generation time). If the current layout's hash differs,
 * the form has been edited in FormBuilder and this action will refuse to
 * overwrite unless force=true.
 */
class GenerateAfform extends \Civi\Api4\Generic\AbstractAction {

  /**
   * ID of the ContributionPage to generate a form for.
   *
   * @var int
   * @required
   */
  protected int $id;

  /**
   * Template name (must match a file in afform-templates/ContributionPage/).
   *
   * @var string
   */
  protected string $template = 'standard';

  /**
   * Overwrite even if the form has been customized in FormBuilder.
   *
   * @var bool
   */
  protected bool $force = FALSE;

  public function _run(\Civi\Api4\Generic\Result $result): void {
    $page = \Civi\Api4\ContributionPage::get(FALSE)
      ->addSelect('*')
      ->addWhere('id', '=', $this->id)
      ->execute()
      ->first();

    if (!$page) {
      throw new \CRM_Core_Exception("ContributionPage {$this->id} not found.");
    }

    $formName = ContributionPageAfformProvider::NAME_PREFIX . $page['id'];

    // Check for an existing saved form and whether its layout has been customized.
    $existing = \Civi\Api4\Afform::get(FALSE)
      ->addSelect('autogen_layout_hash', 'layout')
      ->addWhere('name', '=', $formName)
      ->addWhere('has_local', '=', TRUE)
      ->execute()
      ->first();

    if ($existing && !$this->force) {
      $layout = ContributionPageAfformProvider::buildLayout($page, $this->template);
      if ($existing['autogen_layout_hash'] !== md5($layout)) {
        throw new \CRM_Core_Exception(
          "Afform '$formName' has been customized in FormBuilder. Pass force=true to overwrite."
        );
      }
    }

    $meta = ContributionPageAfformProvider::buildAfformMeta($page, TRUE, $this->template);

    \Civi\Api4\Afform::save(FALSE)
      ->addRecord($meta)
      ->execute();

    $result[] = [
      'name'     => $formName,
      'template' => $this->template,
      'action'   => $existing ? 'regenerated' : 'created',
    ];
  }

}
