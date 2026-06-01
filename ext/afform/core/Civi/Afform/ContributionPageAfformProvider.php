<?php

namespace Civi\Afform;

use Civi\Core\Event\GenericHookEvent;

/**
 * Generates a FormBuilder form for each active ContributionPage.
 *
 * Each form is named afformContributionPage{id} and appears in the
 * main FormBuilder list as a 'form' type.
 *
 * Templates are afforms named afformContributionPageTemplate{Name} with is_template=true.
 * They can be shipped by any extension or added manually to the site-local ang/ directory.
 * Sections in templates marked with af-template-block="feature" on a <fieldset>
 * are stripped when the corresponding ContributionPage feature is disabled.
 * NOTE: af-template-block is only supported on <fieldset> elements (not <div>)
 * to allow unambiguous regex-based removal without an HTML parser.
 *
 * @service
 * @internal
 */
class ContributionPageAfformProvider extends \Civi\Core\Service\AutoSubscriber {

  public const NAME_PREFIX = 'afformContributionPage';

  public const TEMPLATE_NAME_PREFIX = 'afformContributionPageTemplate';

  /**
   * Maps af-template-block names to ContributionPage field conditions.
   * Each value is either a field name (truthy check) or a callable(array $page): bool.
   */
  public const BLOCK_CONDITIONS = [
    'pay_later' => 'is_pay_later',
    'recurring'  => 'is_recur',
  ];

public static function getSubscribedEvents(): array {
    return ['civi.afform.get' => ['addContributionPageForms', -100]];
  }

  public function addContributionPageForms(GenericHookEvent $event): void {
    if ($event->getTypes && !in_array('form', $event->getTypes)) {
      return;
    }

    if (!empty($event->getNames['name'])) {
      $relevant = array_filter($event->getNames['name'], fn($n) => str_starts_with($n, self::NAME_PREFIX));
      if (!$relevant) {
        return;
      }
    }

    $pages = \Civi\Api4\ContributionPage::get(FALSE)
      ->addSelect('*')
      ->addWhere('is_active', '=', TRUE)
      ->execute();

    foreach ($pages as $page) {
      $afformName = self::NAME_PREFIX . $page['id'];

      if (!empty($event->getNames['name']) && !in_array($afformName, $event->getNames['name'])) {
        continue;
      }

      $event->afforms[$afformName] = self::buildAfformMeta($page, $event->getLayout);
    }
  }

  /**
   * Build the full afform metadata array for a ContributionPage.
   *
   * @param array $page ContributionPage record (must include all PAGE_FIELDS)
   * @param bool $getLayout Whether to include generated layout HTML
   * @param string $template Template name
   * @return array
   */
  public static function buildAfformMeta(array $page, bool $getLayout = TRUE, string $template = 'standard'): array {
    $afform = [
      'name'               => self::NAME_PREFIX . $page['id'],
      'type'               => 'form',
      'title'              => $page['frontend_title'] ?: $page['name'],
      'description'        => '',
      'permission'         => ['access CiviCRM'],
      'permission_operator' => 'AND',
      'is_public'          => FALSE,
      'autogen_source'     => 'ContributionPage:' . $page['id'],
      'autogen_template'   => $template,
    ];

    if ($getLayout) {
      $layout = self::buildLayout($page, $template);
      $afform['layout'] = $layout;
      $afform['autogen_layout_hash'] = md5($layout);
    }

    return $afform;
  }

  /**
   * Build layout HTML from a named template, substituting page values and
   * stripping blocks for disabled features.
   *
   * @param array $page
   * @param string $template Template name (filename without .aff.html)
   * @return string
   * @throws \CRM_Core_Exception
   */
  public static function buildLayout(array $page, string $template = 'standard'): string {
    $templateName = self::TEMPLATE_NAME_PREFIX . ucfirst($template);
    $templateAfform = \Civi\Api4\Afform::get(FALSE)
      ->addSelect('layout')
      ->setLayoutFormat('html')
      ->addWhere('name', '=', $templateName)
      ->execute()
      ->first();

    if (!$templateAfform) {
      throw new \CRM_Core_Exception("ContributionPage afform template '$template' not found.");
    }

    $html = $templateAfform['layout'];
    $html = self::substitutePlaceholders($html, $page);
    $html = self::stripInactiveBlocks($html, $page);

    return $html;
  }

  private static function substitutePlaceholders(string $html, array $page): string {
    // Escape for a JS single-quoted string context inside an HTML attribute.
    $source = str_replace(["'", '\\'], ["\\'", '\\\\'], $page['frontend_title'] ?: $page['name'] ?? '');

    return strtr($html, [
      '%%CP_SOURCE%%'            => $source,
      '%%CP_INTRO_TEXT%%'        => $page['intro_text'] ?? '',
      '%%CP_FINANCIAL_TYPE_ID%%' => (int) ($page['financial_type_id'] ?? 1),
    ]);
  }

  /**
   * Remove <fieldset af-template-block="name"> blocks whose feature is inactive,
   * and strip the marker attribute from active blocks.
   *
   * Only <fieldset> elements are supported to allow unambiguous removal
   * without a full HTML parser (fieldsets cannot be nested in HTML).
   */
  private static function stripInactiveBlocks(string $html, array $page): string {
    foreach (self::BLOCK_CONDITIONS as $block => $condition) {
      $active = is_callable($condition) ? $condition($page) : !empty($page[$condition]);

      if (!$active) {
        $html = preg_replace(
          '/<fieldset[^>]+af-template-block="' . preg_quote($block, '/') . '"[^>]*>.*?<\/fieldset>/si',
          '',
          $html
        );
      }
      else {
        $html = preg_replace(
          '/(\s+af-template-block="' . preg_quote($block, '/') . '")/',
          '',
          $html
        );
      }
    }
    return $html;
  }

}
