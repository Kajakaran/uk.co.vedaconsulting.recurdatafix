<?php
class CRM_Recurdatafix_Form_Update extends CRM_Core_Form{
  function preProcess(){
    CRM_Utils_System::setTitle("Smart Debit Data - CiviCRM" );
  }
  function buildQuickForm() {
    $this->addButtons(array(
              array(
                'type' => 'submit',
                'name' => ts('Confirm'),
                'isDefault' => TRUE,
                ),
            )
        );
    parent::buildQuickForm();
    }

  function postProcess() {
    $config   = CRM_Core_Config::singleton();
    $extenDr  = $config->extensionsDir;
    $sDbScriptsDir = $extenDr . DIRECTORY_SEPARATOR .'uk.co.vedaconsulting.recurdatafix' .DIRECTORY_SEPARATOR. 'sql' .  DIRECTORY_SEPARATOR;
    CRM_Utils_File::sourceSQLFile(CIVICRM_DSN, sprintf( "%supdate.sql", $sDbScriptsDir ) );
    //CRM_Core_Session::setStatus('Updated table civicrm_recur table with membership id', 'Success', 'info');

    CRM_Utils_System::redirect(CRM_Utils_System::url( 'civicrm/recurdatafix/create', 'reset=1'));
    CRM_Utils_System::civiExit();
  }

}

