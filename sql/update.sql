/* update contribution pages with 'is_recur' set to 1 in order to create recurring record while making donation
by direct debit */
UPDATE `civicrm_contribution_page` cp
INNER JOIN civicrm_payment_processor cpp ON cp.`payment_processor` = cpp.id
SET cp.recur_frequency_unit = 'year', cp.is_recur = 1, `is_recur_installments` = NULL
WHERE cp.`is_recur` = 0 AND cpp.class_name = 'uk.co.vedaconsulting.payment.smartdebitdd' AND cpp.is_test = 0;

/* Set financial type to 2(Members Due) in order to work smart debit import */
INSERT INTO `fmlm_civicrm_dd`.`civicrm_setting` (`id`, `group_name`, `name`, `value`, `domain_id`, `contact_id`, `is_domain`, `component_id`, `created_date`, `created_id`) 
VALUES (NULL, 'UK Direct Debit', 'financial_type', 's:1:"2";', '1', NULL, '1',NULL, NULL, NULL);

/* update the contact id column from ext id */
UPDATE `civicrm_sd` A 
inner join (SELECT id FROM civicrm_sd WHERE `payerReference` IN ( SELECT `payerReference` FROM civicrm_sd GROUP BY `payerReference` HAVING COUNT(id) > 1 )) B ON A.`id` = B.id
SET `is_valid` = 0;

UPDATE civicrm_sd cs
SET cs.`contact_id` = cs.`payerReference`;

/* update membership based on recur trxn_id */
UPDATE civicrm_sd cs
INNER JOIN civicrm_contribution_recur cr ON cr.trxn_id = cs.transaction_id
INNER JOIN civicrm_membership cm ON cm.contribution_recur_id = cr.id
INNER JOIN civicrm_membership_status cms ON cm.status_id = cms.id
SET cs.`membership_id` = cm.id, cs.`is_valid` = 1, cs.recur_id = cr.id
WHERE cms.is_current_member = 1 AND cs.`membership_id` IS NULL AND cm.status_id IN (1, 2, 3);

/* update the membership column based on contact id */
UPDATE `civicrm_sd` cs
INNER JOIN civicrm_membership cm ON  cm.`contact_id` = cs.`contact_id`
INNER JOIN civicrm_membership_status cms ON cm.status_id = cms.id
SET cs.`membership_id` = cm.id, cs.recur_id = cm.contribution_recur_id
WHERE cms.is_current_member = 1 AND cs.`is_valid` = 1 AND cm.status_id IN (1, 2, 3) AND cs.`membership_id` IS NULL;

/* update membership id for recurring */
UPDATE civicrm_contribution_recur cr
INNER JOIN civicrm_sd cs ON cr.id = cs.recur_id
SET cr.membership_id = cs.membership_id
