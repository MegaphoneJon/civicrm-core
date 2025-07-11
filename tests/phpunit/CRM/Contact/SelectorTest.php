<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 *  Include parent class definition
 */

/**
 *  Test contact custom search functions
 *
 * @package CiviCRM
 * @group headless
 */
class CRM_Contact_SelectorTest extends CiviUnitTestCase {

  public function tearDown(): void {
    $this->quickCleanup(['civicrm_tag', 'civicrm_entity_tag']);
    parent::tearDown();
  }

  /**
   * Test the query from the selector class is consistent with the dataset
   * expectation.
   *
   * @param array $dataSet
   *   The data set to be tested. Note that when adding new datasets often only
   *   form_values and expected where clause will need changing.
   *
   * @dataProvider querySets
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public function testSelectorQuery(array $dataSet): void {
    $tag = $this->callAPISuccess('Tag', 'create', [
      'name' => 'Test Tag Name',
      'parent_id' => 1,
    ]);
    if (!empty($dataSet['limitedPermissions'])) {
      CRM_Core_Config::singleton()->userPermissionClass->permissions = [
        'access CiviCRM',
        'access deleted contacts',
      ];
    }
    $params = CRM_Contact_BAO_Query::convertFormValues($dataSet['form_values'], 0, FALSE, NULL, []);
    $isDeleted = in_array(['deleted_contacts', '=', 1, 0, 0], $params, TRUE);
    foreach ($dataSet['settings'] as $setting) {
      $this->callAPISuccess('Setting', 'create', [$setting['name'] => $setting['value']]);
    }
    $selector = new CRM_Contact_Selector(
      $dataSet['class'],
      $dataSet['form_values'],
      $params,
      $dataSet['return_properties'],
      $dataSet['action'],
      $dataSet['includeContactIds'],
      $dataSet['searchDescendentGroups'],
      $dataSet['context']
    );
    $queryObject = $selector->getQueryObject();
    // Make sure there is no fail on alphabet query.
    $selector->alphabetQuery()->fetchAll();
    $sql = $queryObject->query(FALSE, FALSE, FALSE, $isDeleted);
    foreach ($dataSet['expected_query'] as $index => $queryString) {
      $this->assertLike($this->strWrangle($queryString), $this->strWrangle($sql[$index]));
    }
    if (!empty($dataSet['where_contains'])) {
      $this->assertStringContainsString($this->strWrangle(str_replace('@tagid', $tag['id'], $dataSet['where_contains'])), $this->strWrangle($sql[2]));
    }
    // Ensure that search builder return individual contact as per criteria
    if ($dataSet['context'] === 'builder') {
      $contactID = $this->individualCreate(['first_name' => 'James', 'last_name' => 'Bond']);
      if ('Search builder behaviour for Activity' === $dataSet['description']) {
        $this->callAPISuccess('Activity', 'create', [
          'activity_type_id' => 'Meeting',
          'subject' => 'Test',
          'source_contact_id' => $contactID,
        ]);
        $rows = CRM_Core_DAO::executeQuery(implode(' ', $sql))->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertEquals($contactID, $rows[0]['source_contact_id']);
      }
      else {
        $this->callAPISuccess('EntityTag', 'create', [
          'entity_id' => $contactID,
          'tag_id' => $tag['id'],
          'entity_table' => 'civicrm_contact',
        ]);
        $this->callAPISuccess('Address', 'create', [
          'contact_id' => $contactID,
          'location_type_id' => 'Home',
          'is_primary' => 1,
          'country_id' => 'IN',
        ]);
        $rows = $selector->getRows(CRM_Core_Action::VIEW, 0, 50, '');
        $this->assertCount(1, $rows);

        CRM_Core_DAO::reenableFullGroupByMode();
        $rows = $selector->getRows(CRM_Core_Action::VIEW, 0, 50, '');

        $sortChar = $selector->alphabetQuery()->fetchAll();
        // sort name is stored in '<last_name>, <first_name>' format, as per which the first character would be B of Bond
        $this->assertEquals('B', $sortChar[0]['sort_name']);
        $this->assertEquals($contactID, key($rows));

        CRM_Core_DAO::reenableFullGroupByMode();
        $selector->getQueryObject()->getCachedContacts([$contactID], FALSE);
      }
    }
    if (!empty($dataSet['limitedPermissions'])) {
      $this->cleanUpAfterACLs();
    }
  }

  /**
   * Test advanced search results by uf_group_id.
   */
  public function testSearchByProfile(): void {
    //Create search profile for contacts.
    $this->setTestEntityID('UFGroup', $this->callAPISuccess('UFGroup', 'create', [
      'group_type' => 'Contact',
      'name' => 'test_search_profile',
      'title' => 'Test Search Profile',
      'api.uf_field.create' => [
        [
          'field_name' => 'email',
          'visibility' => 'Public Pages and Listings',
          'field_type' => 'Contact',
          'label' => 'Email',
          'in_selector' => 1,
        ],
      ],
    ])['id'], 'test_profile');
    $contactID = $this->individualCreate(['email' => 'mickey@mouseville.com']);
    //Put the email on hold.
    $email = $this->callAPISuccess('Email', 'get', [
      'sequential' => 1,
      'contact_id' => $contactID,
    ]);
    $this->callAPISuccess('Email', 'create', [
      'id' => $email['id'],
      'on_hold' => 1,
    ]);

    $dataSet = [
      'description' => 'Normal default behaviour',
      'class' => 'CRM_Contact_Selector',
      'settings' => [],
      'form_values' => ['email' => 'mickey@mouseville.com', 'uf_group_id' => $this->ids['UFGroup']['test_profile']],
      'params' => [],
      'return_properties' => NULL,
      'context' => 'advanced',
      'action' => CRM_Core_Action::ADVANCED,
      'includeContactIds' => NULL,
      'searchDescendentGroups' => FALSE,
    ];
    $params = CRM_Contact_BAO_Query::convertFormValues($dataSet['form_values'], 0, FALSE, NULL, []);
    // create CRM_Contact_Selector instance and set desired query params
    $selector = new CRM_Contact_Selector(
      $dataSet['class'],
      $dataSet['form_values'],
      $params,
      $dataSet['return_properties'],
      $dataSet['action'],
      $dataSet['includeContactIds'],
      $dataSet['searchDescendentGroups'],
      $dataSet['context']
    );
    $rows = $selector->getRows(CRM_Core_Action::VIEW, 0, 50, '');
    $this->assertCount(1, $rows);
    $this->assertEquals($contactID, key($rows));

    //Check if email column contains (On Hold) string.
    foreach ($rows[$contactID] as $key => $value) {
      if (str_contains($key, 'email')) {
        $this->assertStringContainsString('(On Hold)', (string) $value);
      }
    }
  }

  /**
   * Test the civicrm_prevnext_cache entry if it correctly stores the search query result
   */
  public function testPrevNextCache(): void {
    $contactID = $this->individualCreate(['email' => 'mickey@mouseville.com']);
    $dataSet = [
      'description' => 'Normal default behaviour',
      'class' => 'CRM_Contact_Selector',
      'settings' => [],
      'form_values' => ['email' => 'mickey@mouseville.com'],
      'params' => [],
      'return_properties' => NULL,
      'context' => 'advanced',
      'action' => CRM_Core_Action::ADVANCED,
      'includeContactIds' => NULL,
      'searchDescendentGroups' => FALSE,
      'expected_query' => [
        0 => 'default',
        1 => 'default',
        2 => "WHERE  ( civicrm_email.email LIKE '%mickey@mouseville.com%' )  AND (contact_a.is_deleted = 0)",
      ],
    ];
    $params = CRM_Contact_BAO_Query::convertFormValues($dataSet['form_values'], 0, FALSE, NULL, []);

    // create CRM_Contact_Selector instance and set desired query params
    $selector = new CRM_Contact_Selector(
      $dataSet['class'],
      $dataSet['form_values'],
      $params,
      $dataSet['return_properties'],
      $dataSet['action'],
      $dataSet['includeContactIds'],
      $dataSet['searchDescendentGroups'],
      $dataSet['context']
    );
    // set cache key
    $selector->setKey('abc');

    // fetch row and check the result
    $rows = $selector->getRows(CRM_Core_Action::VIEW, 0, 1, NULL);
    $this->assertCount(1, $rows);
    $this->assertEquals($contactID, key($rows));

    // build cache key and use to it to fetch prev-next cache record
    $cacheKey = 'civicrm search abc';
    $contacts = CRM_Utils_SQL_Select::from('civicrm_prevnext_cache')
      ->select(['entity_id1', 'cachekey'])
      ->where('cachekey = @key')
      ->param('key', $cacheKey)
      ->execute()
      ->fetchAll();
    $this->assertCount(1, $contacts);
    // check the prevNext record matches
    $expectedEntry = [
      'entity_id1' => $contactID,
      'cachekey' => $cacheKey,
    ];
    $this->checkArrayEquals($contacts[0], $expectedEntry);
  }

  /**
   * Data sets for testing.
   */
  public static function querySets(): array {
    return [
      'Empty group test' => [
        [
          'description' => 'Empty group test',
          'class' => 'CRM_Contact_Selector',
          'settings' => [],
          'form_values' => [['contact_type', '=', 'Individual', 1, 0], ['group', 'IS NULL', '', 1, 0]],
          'params' => [],
          'return_properties' => NULL,
          'context' => 'builder',
          'action' => CRM_Core_Action::NONE,
          'includeContactIds' => NULL,
          'searchDescendentGroups' => FALSE,
          'expected_query' => [],
        ],
      ],
      'Tag Equals Test' => [
        [
          'description' => 'Tag Equals Test',
          'class' => 'CRM_Contact_Selector',
          'settings' => [],
          'form_values' => [['contact_type', '=', 'Individual', 1, 0], ['tag', '=', '1', 1, 0]],
          'params' => [],
          'return_properties' => NULL,
          'context' => 'builder',
          'action' => CRM_Core_Action::NONE,
          'includeContactIds' => NULL,
          'searchDescendentGroups' => FALSE,
          'expected_query' => [],
          'where_contains' => 'tag_id IN ( @tagid )',
        ],
      ],
      'Normal default behaviour' => [
        [
          'description' => 'Normal default behaviour',
          'class' => 'CRM_Contact_Selector',
          'settings' => [],
          'form_values' => ['email' => 'mickey@mouseville.com'],
          'params' => [],
          'return_properties' => NULL,
          'context' => 'advanced',
          'action' => CRM_Core_Action::ADVANCED,
          'includeContactIds' => NULL,
          'searchDescendentGroups' => FALSE,
          'expected_query' => [
            0 => self::getDefaultSelectString(),
            1 => self::getDefaultFromString(),
            2 => "WHERE  ( civicrm_email.email LIKE '%mickey@mouseville.com%' )  AND ( 1 ) AND (contact_a.is_deleted = 0)",
          ],
        ],
      ],
      'Normal default + user added wildcard' => [
        [
          'description' => 'Normal default + user added wildcard',
          'class' => 'CRM_Contact_Selector',
          'settings' => [],
          'form_values' => ['email' => '%mickey@mouseville.com', 'sort_name' => 'Mouse'],
          'params' => [],
          'return_properties' => NULL,
          'context' => 'advanced',
          'action' => CRM_Core_Action::ADVANCED,
          'includeContactIds' => NULL,
          'searchDescendentGroups' => FALSE,
          'expected_query' => [
            0 => self::getDefaultSelectString(),
            1 => self::getDefaultFromString(),
            2 => "WHERE  ( civicrm_email.email LIKE '%mickey@mouseville.com%'  AND ( ( ( contact_a.sort_name LIKE '%Mouse%' ) OR ( civicrm_email.email LIKE '%Mouse%' ) ) ) ) AND ( 1 ) AND (contact_a.is_deleted = 0)",
          ],
        ],
      ],
      'Site set to not pre-pend wildcard' => [
        [
          'description' => 'Site set to not pre-pend wildcard',
          'class' => 'CRM_Contact_Selector',
          'settings' => [['name' => 'includeWildCardInName', 'value' => FALSE]],
          'form_values' => ['email' => 'mickey@mouseville.com', 'sort_name' => 'Mouse'],
          'params' => [],
          'return_properties' => NULL,
          'context' => 'advanced',
          'action' => CRM_Core_Action::ADVANCED,
          'includeContactIds' => NULL,
          'searchDescendentGroups' => FALSE,
          'expected_query' => [
            0 => self::getDefaultSelectString(),
            1 => self::getDefaultFromString(),
            2 => "WHERE  ( civicrm_email.email LIKE 'mickey@mouseville.com%'  AND ( ( ( contact_a.sort_name LIKE 'Mouse%' ) OR ( civicrm_email.email LIKE 'Mouse%' ) ) ) ) AND ( 1 ) AND (contact_a.is_deleted = 0)",
          ],
        ],
      ],
      'Site set to not pre-pend wildcard and check that trash value is respected' => [
        [
          'description' => 'Site set to not pre-pend wildcard and check that trash value is respected',
          'class' => 'CRM_Contact_Selector',
          'settings' => [['name' => 'includeWildCardInName', 'value' => FALSE]],
          'form_values' => ['email' => 'mickey@mouseville.com', 'sort_name' => 'Mouse', 'deleted_contacts' => 1],
          'params' => [],
          'return_properties' => NULL,
          'context' => 'advanced',
          'action' => CRM_Core_Action::ADVANCED,
          'includeContactIds' => NULL,
          'searchDescendentGroups' => FALSE,
          'expected_query' => [
            0 => self::getDefaultSelectString(),
            1 => self::getDefaultFromString(),
            2 => "WHERE  ( civicrm_email.email LIKE 'mickey@mouseville.com%'  AND ( ( ( contact_a.sort_name LIKE 'Mouse%' ) OR ( civicrm_email.email LIKE 'Mouse%' ) ) ) ) AND ( 1 ) AND (contact_a.is_deleted)",
          ],
        ],
      ],
      'Ensure that the Join to the acl contact cache is correct' => [
        [
          'description' => 'Ensure that the Join to the acl contact cache is correct and that if we are searching in deleted contacts appropriate where clause is added',
          'class' => 'CRM_Contact_Selector',
          'settings' => [['name' => 'includeWildCardInName', 'value' => FALSE]],
          'form_values' => ['email' => 'mickey@mouseville.com', 'sort_name' => 'Mouse', 'deleted_contacts' => 1],
          'params' => [],
          'return_properties' => NULL,
          'context' => 'advanced',
          'action' => CRM_Core_Action::ADVANCED,
          'includeContactIds' => NULL,
          'searchDescendentGroups' => FALSE,
          'limitedPermissions' => TRUE,
          'expected_query' => [
            0 => self::getDefaultSelectString(),
            1 => 'FROM civicrm_contact contact_a LEFT JOIN civicrm_address ON ( contact_a.id = civicrm_address.contact_id AND civicrm_address.is_primary = 1 ) LEFT JOIN civicrm_country ON ( civicrm_address.country_id = civicrm_country.id ) LEFT JOIN civicrm_email ON (contact_a.id = civicrm_email.contact_id AND civicrm_email.is_primary = 1) LEFT JOIN civicrm_phone ON (contact_a.id = civicrm_phone.contact_id AND civicrm_phone.is_primary = 1) LEFT JOIN civicrm_im ON (contact_a.id = civicrm_im.contact_id AND civicrm_im.is_primary = 1) LEFT JOIN civicrm_worldregion ON civicrm_country.region_id = civicrm_worldregion.id INNER JOIN civicrm_acl_contact_cache aclContactCache ON contact_a.id = aclContactCache.contact_id',
            2 => "WHERE  ( civicrm_email.email LIKE 'mickey@mouseville.com%'  AND ( ( ( contact_a.sort_name LIKE 'Mouse%' ) OR ( civicrm_email.email LIKE 'Mouse%' ) ) ) ) AND  aclContactCache.user_id = 0 AND aclContactCache.domain_id = 1 AND (contact_a.is_deleted)",
          ],
        ],
      ],
      'Ensure that the Join to the acl contact cache is correct, no deleted contacts' => [
        [
          'description' => 'Ensure that the Join to the acl contact cache is correct and that if we are not searching in the trash trashed contacts are not returned',
          'class' => 'CRM_Contact_Selector',
          'settings' => [['name' => 'includeWildCardInName', 'value' => FALSE]],
          'form_values' => ['email' => 'mickey@mouseville.com', 'sort_name' => 'Mouse'],
          'params' => [],
          'return_properties' => NULL,
          'context' => 'advanced',
          'action' => CRM_Core_Action::ADVANCED,
          'includeContactIds' => NULL,
          'searchDescendentGroups' => FALSE,
          'limitedPermissions' => TRUE,
          'expected_query' => [
            0 => self::getDefaultSelectString(),
            1 => 'FROM civicrm_contact contact_a LEFT JOIN civicrm_address ON ( contact_a.id = civicrm_address.contact_id AND civicrm_address.is_primary = 1 ) LEFT JOIN civicrm_country ON ( civicrm_address.country_id = civicrm_country.id ) LEFT JOIN civicrm_email ON (contact_a.id = civicrm_email.contact_id AND civicrm_email.is_primary = 1) LEFT JOIN civicrm_phone ON (contact_a.id = civicrm_phone.contact_id AND civicrm_phone.is_primary = 1) LEFT JOIN civicrm_im ON (contact_a.id = civicrm_im.contact_id AND civicrm_im.is_primary = 1) LEFT JOIN civicrm_worldregion ON civicrm_country.region_id = civicrm_worldregion.id INNER JOIN civicrm_acl_contact_cache aclContactCache ON contact_a.id = aclContactCache.contact_id',
            2 => "WHERE  ( civicrm_email.email LIKE 'mickey@mouseville.com%'  AND ( ( ( contact_a.sort_name LIKE 'Mouse%' ) OR ( civicrm_email.email LIKE 'Mouse%' ) ) ) ) AND  aclContactCache.user_id = 0 AND aclContactCache.domain_id = 1 AND (contact_a.is_deleted = 0)",
          ],
        ],
      ],
      'Use of quotes for exact string' => [
        [
          'description' => 'Use of quotes for exact string',
          'use_case_comments' => 'This is something that was in the code but seemingly not working. No UI info on it though!',
          'class' => 'CRM_Contact_Selector',
          'settings' => [['name' => 'includeWildCardInName', 'value' => FALSE]],
          'form_values' => ['email' => '"mickey@mouseville.com"', 'sort_name' => 'Mouse'],
          'params' => [],
          'return_properties' => NULL,
          'context' => 'advanced',
          'action' => CRM_Core_Action::ADVANCED,
          'includeContactIds' => NULL,
          'searchDescendentGroups' => FALSE,
          'expected_query' => [
            0 => self::getDefaultSelectString(),
            1 => self::getDefaultFromString(),
            2 => "WHERE  ( civicrm_email.email = 'mickey@mouseville.com'  AND ( ( ( contact_a.sort_name LIKE 'Mouse%' ) OR ( civicrm_email.email LIKE 'Mouse%' ) ) ) ) AND ( 1 ) AND (contact_a.is_deleted = 0)",
          ],
        ],
      ],
      'Normal search builder behaviour' => [
        [
          'description' => 'Normal search builder behaviour',
          'class' => 'CRM_Contact_Selector',
          'settings' => [],
          'form_values' => ['contact_type' => 'Individual', 'country' => ['IS NOT NULL' => 1]],
          'params' => [],
          'return_properties' => [
            'contact_type' => 1,
            'contact_sub_type' => 1,
            'sort_name' => 1,
          ],
          'context' => 'builder',
          'action' => CRM_Core_Action::NONE,
          'includeContactIds' => NULL,
          'searchDescendentGroups' => FALSE,
          'expected_query' => [
            0 => 'SELECT contact_a.id as contact_id, contact_a.contact_type as `contact_type`, contact_a.contact_sub_type as `contact_sub_type`, contact_a.sort_name as `sort_name`, civicrm_address.id as address_id, civicrm_address.country_id as country_id',
            1 => ' FROM civicrm_contact contact_a LEFT JOIN civicrm_address ON ( contact_a.id = civicrm_address.contact_id AND civicrm_address.is_primary = 1 )',
            2 => 'WHERE ( contact_a.contact_type IN ("Individual") AND civicrm_address.country_id IS NOT NULL ) AND ( 1 ) AND  (contact_a.is_deleted = 0)',
          ],
        ],
      ],
      'Search builder behaviour for Activity' => [
        [
          'description' => 'Search builder behaviour for Activity',
          'class' => 'CRM_Contact_Selector',
          'settings' => [],
          'form_values' => ['source_contact_id' => ['IS NOT NULL' => 1]],
          'params' => [],
          'return_properties' => [
            'source_contact_id' => 1,
          ],
          'context' => 'builder',
          'action' => CRM_Core_Action::NONE,
          'includeContactIds' => NULL,
          'searchDescendentGroups' => FALSE,
          'expected_query' => [
            0 => 'SELECT contact_a.id as contact_id, source_contact.id as source_contact_id',
          ],
          'where_contains' => 'WHERE ( source_contact.id IS NOT NULL ) AND ( 1 ) AND (contact_a.is_deleted = 0)',
        ],
      ],
      'Test display relationships' => [
        [
          'description' => 'Test display relationships',
          'class' => 'CRM_Contact_Selector',
          'settings' => [],
          'form_values' => ['display_relationship_type' => '1_b_a'],
          'return_properties' => NULL,
          'params' => [],
          'context' => 'advanced',
          'action' => CRM_Core_Action::NONE,
          'includeContactIds' => NULL,
          'searchDescendentGroups' => FALSE,
          'expected_query' => [
            0 => 'SELECT contact_a.id as contact_id, contact_a.contact_type as `contact_type`, contact_a.contact_sub_type as `contact_sub_type`, contact_a.sort_name as `sort_name`, contact_a.display_name as `display_name`, contact_a.external_identifier as `external_identifier`, IF ( contact_a.contact_type = \'Individual\', NULL, contact_a.organization_name ) as organization_name, contact_a.first_name as `first_name`, contact_a.middle_name as `middle_name`, contact_a.last_name as `last_name`, contact_a.do_not_email as `do_not_email`, contact_a.do_not_phone as `do_not_phone`, contact_a.do_not_mail as `do_not_mail`, contact_a.do_not_sms as `do_not_sms`, contact_a.do_not_trade as `do_not_trade`, contact_a.is_opt_out as `is_opt_out`, contact_a.legal_identifier as `legal_identifier`, contact_a.nick_name as `nick_name`, contact_a.legal_name as `legal_name`, contact_a.image_URL as `image_URL`, contact_a.preferred_communication_method as `preferred_communication_method`, contact_a.preferred_language as `preferred_language`, contact_a.source as `contact_source`, contact_a.prefix_id as `prefix_id`, contact_a.suffix_id as `suffix_id`, contact_a.formal_title as `formal_title`, contact_a.communication_style_id as `communication_style_id`, contact_a.job_title as `job_title`, contact_a.gender_id as `gender_id`, contact_a.birth_date as `birth_date`, contact_a.is_deceased as `is_deceased`, contact_a.deceased_date as `deceased_date`, contact_a.household_name as `household_name`, contact_a.sic_code as `sic_code`, contact_a.is_deleted as `contact_is_deleted`, IF ( contact_a.contact_type = \'Individual\', contact_a.organization_name, NULL ) as current_employer, civicrm_address.id as address_id, civicrm_address.street_address as `street_address`, civicrm_address.supplemental_address_1 as `supplemental_address_1`, civicrm_address.supplemental_address_2 as `supplemental_address_2`, civicrm_address.supplemental_address_3 as `supplemental_address_3`, civicrm_address.city as `city`, civicrm_address.postal_code_suffix as `postal_code_suffix`, civicrm_address.postal_code as `postal_code`, civicrm_address.geo_code_1 as `geo_code_1`, civicrm_address.geo_code_2 as `geo_code_2`, civicrm_address.state_province_id as state_province_id, civicrm_address.country_id as country_id, civicrm_phone.id as phone_id, civicrm_phone.phone_type_id as phone_type_id, civicrm_phone.phone as `phone`, civicrm_email.id as email_id, civicrm_email.email as `email`, civicrm_email.on_hold as `on_hold`, civicrm_im.id as im_id, civicrm_im.provider_id as provider_id, civicrm_im.name as `im`, civicrm_worldregion.id as worldregion_id, civicrm_worldregion.name as `world_region`',
            2 => 'WHERE displayRelType.relationship_type_id = 1
AND   displayRelType.is_active = 1
AND ( 1 ) AND (contact_a.is_deleted = 0)',
          ],
        ],
      ],
    ];
  }

  /**
   * Test the contact ID query does not fail on country search.
   */
  public function testContactIDQuery(): void {
    $params = [
      [
        0 => 'country-1',
        1 => '=',
        2 => '1228',
        3 => 1,
        4 => 0,
      ],
    ];

    $searchOBJ = new CRM_Contact_Selector(NULL);
    $searchOBJ->contactIDQuery($params, '1_u');
  }

  /**
   * Test the Search Builder using Non ASCII location type for email filter
   */
  public function testSelectorQueryOnNonASCILocationType(): void {
    $contactID = $this->individualCreate();
    $locationTypeID = $this->locationTypeCreate([
      'name' => 'Non ASCII Location Type',
      'display_name' => 'Дом Location type',
      'vcard_name' => 'Non ASCII Location Type',
      'is_active' => 1,
    ]);
    $this->callAPISuccess('Email', 'create', [
      'contact_id' => $contactID,
      'location_type_id' => $locationTypeID,
      'email' => 'test@test.com',
    ]);

    $selector = new CRM_Contact_Selector(
      'CRM_Contact_Selector',
      ['email' => ['IS NOT NULL' => 1]],
      [
        [
          0 => 'email-' . $locationTypeID,
          1 => 'IS NOT NULL',
          2 => NULL,
          3 => 1,
          4 => 0,
        ],
      ],
      [
        'contact_type' => 1,
        'contact_sub_type' => 1,
        'sort_name' => 1,
        'location' => [
          'Non ASCII Location Type' => [
            'location_type' => $locationTypeID,
            'email' => 1,
          ],
        ],
      ],
      CRM_Core_Action::NONE,
      NULL,
      FALSE,
      'builder'
    );

    $sql = $selector->getQueryObject()->query();

    $expectedQuery = [
      0 => 'SELECT contact_a.id as contact_id, contact_a.contact_type as `contact_type`, contact_a.contact_sub_type as `contact_sub_type`, contact_a.sort_name as `sort_name`, `Non_ASCII_Location_Type-location_type`.id as `Non_ASCII_Location_Type-location_type_id`, `Non_ASCII_Location_Type-location_type`.name as `Non_ASCII_Location_Type-location_type`, `Non_ASCII_Location_Type-address`.id as `Non_ASCII_Location_Type-address_id`, `Non_ASCII_Location_Type-email`.id as `Non_ASCII_Location_Type-email_id`, `Non_ASCII_Location_Type-email`.email as `Non_ASCII_Location_Type-email`',
      // @TODO these FROM clause doesn't matches due to extra spaces or special character
      2 => 'WHERE  (  ( `Non_ASCII_Location_Type-email`.email IS NOT NULL )  )  AND ( 1 ) AND (contact_a.is_deleted = 0)',
    ];
    foreach ($expectedQuery as $index => $queryString) {
      $this->assertEquals($this->strWrangle($queryString), $this->strWrangle($sql[$index]));
    }

    $rows = $selector->getRows(CRM_Core_Action::VIEW, 0, 1, NULL);
    $this->assertCount(1, $rows);
    $this->assertEquals($contactID, key($rows));
    $this->assertEquals('test@test.com', $rows[$contactID]['Non_ASCII_Location_Type-email']);
  }

  /**
   * Test the value use in where clause if it's case sensitive or not against each MySQL operators
   */
  public function testWhereClauseByOperator(): void {
    $contactID = $this->individualCreate(['first_name' => 'Adam']);

    $filters = [
      'IS NOT NULL' => 1,
      '=' => 'Adam',
      'LIKE' => '%Ad%',
      'RLIKE' => '^A[a-z]{3}$',
      'IN' => ['IN' => ['Adam']],
    ];
    $filtersByWhereClause = [
      // doesn't matter
      'IS NOT NULL' => '( contact_a.first_name IS NOT NULL )',
      // case sensitive check
      '=' => "( contact_a.first_name = 'Adam' )",
      // case insensitive check
      'LIKE' => "( contact_a.first_name LIKE '%Ad%' )",
      // case sensitive check
      'RLIKE' => "(  CAST(contact_a.first_name AS BINARY) RLIKE BINARY '^A[a-z]{3}$'  )",
      // case sensitive check
      'IN' => '( contact_a.first_name IN ("Adam") )',
    ];
    foreach ($filters as $op => $filter) {
      $selector = new CRM_Contact_Selector(
        'CRM_Contact_Selector',
        ['first_name' => [$op => $filter]],
        [
          [
            0 => 'first_name',
            1 => $op,
            2 => $filter,
            3 => 1,
            4 => 0,
          ],
        ],
        [],
        CRM_Core_Action::NONE,
        NULL,
        FALSE,
        'builder'
      );

      $sql = $selector->getQueryObject()->query();
      $this->assertEquals(TRUE, strpos($sql[2], $filtersByWhereClause[$op]));

      $rows = $selector->getRows(CRM_Core_Action::VIEW, 0, 1, NULL);
      $this->assertCount(1, $rows);
      $this->assertEquals($contactID, key($rows));
    }
  }

  /**
   * Test if custom table is added in from clause when
   * search results are ordered by a custom field.
   *
   * @throws \CRM_Core_Exception
   */
  public function testSelectorQueryOrderByCustomField(): void {
    //Search for any params.
    $params = [
      [
        0 => 'country-1',
        1 => '=',
        2 => '1228',
        3 => 1,
        4 => 0,
      ],
    ];

    //Create a test custom group and field.
    $customGroup = $this->callAPISuccess('CustomGroup', 'create', [
      'title' => 'test custom group',
      'extends' => 'Individual',
    ]);
    $cgTableName = $customGroup['values'][$customGroup['id']]['table_name'];
    $customField = $this->callAPISuccess('CustomField', 'create', [
      'custom_group_id' => $customGroup['id'],
      'label' => 'test field',
      'html_type' => 'Text',
    ]);
    $customFieldId = $customField['id'];

    //Sort by the custom field created above.
    $sortParams = [
      1 => [
        'name' => 'test field',
        'sort' => "custom_$customFieldId",
      ],
    ];
    $sort = new CRM_Utils_Sort($sortParams, '1_d');

    //Form a query to order by a custom field.
    $query = new CRM_Contact_BAO_Query($params,
      CRM_Contact_BAO_Query::NO_RETURN_PROPERTIES,
      NULL, FALSE, FALSE, 1,
      FALSE, TRUE, TRUE, NULL,
      'AND'
    );
    $query->searchQuery(0, 0, $sort);
    //Check if custom table is included in $query->_tables.
    $this->assertArrayHasKey($cgTableName, $query->_tables);
    //Assert if from clause joins the custom table.
    $this->assertNotFalse(strpos($query->_fromClause, $cgTableName));
    $this->callAPISuccess('CustomField', 'delete', ['id' => $customField['id']]);
    $this->callAPISuccess('CustomGroup', 'delete', ['id' => $customGroup['id']]);
  }

  /**
   * Check where clause of a date custom field when 'IS NOT EMPTY' operator is used
   */
  public function testCustomDateField(): void {
    $contactID = $this->individualCreate();
    //Create a test custom group and field.
    $customGroup = $this->callAPISuccess('CustomGroup', 'create', [
      'title' => 'test custom group',
      'extends' => 'Individual',
    ]);
    $customGroupTableName = $customGroup['values'][$customGroup['id']]['table_name'];

    $createdField = $this->callAPISuccess('customField', 'create', [
      'data_type' => 'Date',
      'html_type' => 'Select Date',
      'date_format' => 'd M yy',
      'time_format' => 1,
      'label' => 'test field',
      'custom_group_id' => $customGroup['id'],
    ]);
    $customFieldColumnName = $createdField['values'][$createdField['id']]['column_name'];

    $this->callAPISuccess('Contact', 'create', [
      'id' => $contactID,
      'custom_' . $createdField['id'] => date('YmdHis'),
    ]);

    $selector = new CRM_Contact_Selector(
      'CRM_Contact_Selector',
      ['custom_' . $createdField['id'] => ['IS NOT EMPTY' => 1]],
      [
        [
          0 => 'custom_' . $createdField['id'],
          1 => 'IS NOT NULL',
          2 => 1,
          3 => 1,
          4 => 0,
        ],
      ],
      [],
      CRM_Core_Action::NONE,
      NULL,
      FALSE,
      'builder'
    );

    $whereClause = $selector->getQueryObject()->query()[2];
    $expectedClause = sprintf('( %s.%s IS NOT NULL )', $customGroupTableName, $customFieldColumnName);
    // test the presence of expected date clause
    $this->assertEquals(TRUE, strpos($whereClause, $expectedClause));

    $rows = $selector->getRows(CRM_Core_Action::VIEW, 0, 1, NULL);
    $this->assertCount(1, $rows);
  }

  /**
   * Get the default select string since this is generally consistent.
   */
  public static function getDefaultSelectString(): string {
    return 'SELECT contact_a.id as contact_id, contact_a.contact_type  as `contact_type`, contact_a.contact_sub_type  as `contact_sub_type`, contact_a.sort_name  as `sort_name`,'
      . ' contact_a.display_name  as `display_name`, contact_a.external_identifier  as `external_identifier`,'
      . ' IF ( contact_a.contact_type = \'Individual\', NULL, contact_a.organization_name ) as organization_name,'
      . ' contact_a.first_name  as `first_name`, contact_a.middle_name  as `middle_name`, contact_a.last_name  as `last_name`,'
      . ' contact_a.do_not_email  as `do_not_email`, contact_a.do_not_phone as `do_not_phone`, contact_a.do_not_mail  as `do_not_mail`,'
      . ' contact_a.do_not_sms  as `do_not_sms`, contact_a.do_not_trade as `do_not_trade`, contact_a.is_opt_out  as `is_opt_out`, contact_a.legal_identifier  as `legal_identifier`,'
      . ' contact_a.nick_name  as `nick_name`, contact_a.legal_name  as `legal_name`, contact_a.image_URL  as `image_URL`,'
      . ' contact_a.preferred_communication_method  as `preferred_communication_method`, contact_a.preferred_language  as `preferred_language`, contact_a.source as `contact_source`,'
      . ' contact_a.prefix_id  as `prefix_id`, contact_a.suffix_id  as `suffix_id`, contact_a.formal_title  as `formal_title`, contact_a.communication_style_id  as `communication_style_id`,'
      . ' contact_a.job_title  as `job_title`, contact_a.gender_id  as `gender_id`, contact_a.birth_date  as `birth_date`, contact_a.is_deceased  as `is_deceased`,'
      . ' contact_a.deceased_date  as `deceased_date`, contact_a.household_name  as `household_name`,'
      . ' contact_a.sic_code  as `sic_code`, contact_a.is_deleted  as `contact_is_deleted`,'
      . ' IF ( contact_a.contact_type = \'Individual\', contact_a.organization_name, NULL ) as current_employer, civicrm_address.id as address_id,'
      . ' civicrm_address.street_address as `street_address`, civicrm_address.supplemental_address_1 as `supplemental_address_1`, '
      . 'civicrm_address.supplemental_address_2 as `supplemental_address_2`, civicrm_address.supplemental_address_3 as `supplemental_address_3`, civicrm_address.city as `city`, civicrm_address.postal_code_suffix as `postal_code_suffix`, '
      . 'civicrm_address.postal_code as `postal_code`, civicrm_address.geo_code_1 as `geo_code_1`, civicrm_address.geo_code_2 as `geo_code_2`, '
      . 'civicrm_address.state_province_id as state_province_id, civicrm_address.country_id as country_id, civicrm_phone.id as phone_id, civicrm_phone.phone_type_id as phone_type_id, '
      . 'civicrm_phone.phone as `phone`, civicrm_email.id as email_id, civicrm_email.email as `email`, civicrm_email.on_hold as `on_hold`, civicrm_im.id as im_id, '
      . 'civicrm_im.provider_id as provider_id, civicrm_im.name as `im`, civicrm_worldregion.id as worldregion_id, civicrm_worldregion.name as `world_region`';
  }

  /**
   * Get the default from string since this is generally consistent.
   */
  public static function getDefaultFromString(): string {
    return ' FROM civicrm_contact contact_a LEFT JOIN civicrm_address ON ( contact_a.id = civicrm_address.contact_id AND civicrm_address.is_primary = 1 )'
      . ' LEFT JOIN civicrm_country ON ( civicrm_address.country_id = civicrm_country.id ) '
      . ' LEFT JOIN civicrm_email ON (contact_a.id = civicrm_email.contact_id AND civicrm_email.is_primary = 1)'
      . ' LEFT JOIN civicrm_phone ON (contact_a.id = civicrm_phone.contact_id AND civicrm_phone.is_primary = 1)'
      . ' LEFT JOIN civicrm_im ON (contact_a.id = civicrm_im.contact_id AND civicrm_im.is_primary = 1) '
      . 'LEFT JOIN civicrm_worldregion ON civicrm_country.region_id = civicrm_worldregion.id ';
  }

  /**
   * Strangle strings into a more matchable format.
   *
   * @param string $string
   *
   * @return string
   */
  public function strWrangle(string $string): string {
    return trim(str_replace('  ', ' ', $string));
  }

}
