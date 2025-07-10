<?php 
namespace Drupal\webform_validate_country\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use \Drupal\Core\TempStore\TempStoreException;

use Drupal\ip2location_api\IplookupInterface;

use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;

use Symfony\Component\DependencyInjection\ContainerInterface; 

/** 
 * Webform Validate Country Handler
 * 
 * @WebformHandler( 
 *   id = "webform_validate_country_handler", 
 *   label = @Translation("Webform Validate Country"), 
 *   category = @Translation("Webform Validate Country"), 
 *   description = @Translation("Webform Validate Country submission handler."), 
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE, 
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED, 
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 * ) 
 */ 
class ValidateCountryHandler extends WebformHandlerBase { 
    
    /**
     * IP 2 Location Service
     *
     * @var \Drupal\ip2location_api\IplookupInterface
     */
    private IplookupInterface $ip2LocationAPI;

    /**
     * @var string Default/Fallback Validation Message
     */
    private string $defaultValidationMsg;

    /**
     * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
     */
    private PrivateTempStoreFactory $tempStore;



  /** 
   * {@inheritdoc} 
   */ 
  public static function create(
    ContainerInterface $container, 
    array $configuration, 
    $plugin_id, 
    $plugin_definition) { 
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition); 
    $instance->ip2LocationAPI = $container->get('ip2location_api.iplookup');
    $instance->tempStore = $container->get('tempstore.private');
    $instance->defaultValidationMsg = 'Are you sure you are not from %value.';
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['country_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Field Key to validate on'),
      '#default_value' => $this->configuration['country_field'],
      '#required' => TRUE,
    ];
    $form['failed_to_validate_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('If present we will store a 1/0 value based on whether we pass/fail validation in this field.'),
      '#default_value' => $this->configuration['failed_to_validate_field'],
      '#required' => FALSE,
    ];
    $form['validation_failure_msg'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default validation message - include %value to show the country we think you are from.'),
      '#default_value' => $this->configuration['validation_failure_msg']?? $this->defaultValidationMsg,
      '#required' => TRUE,
    ];
    $form['failures_before_allow'] = [  
      '#type' => 'number',
      '#title' => $this->t('Repeat failed validations required before we allow country that fails to lookup.'),
      '#min' => 1, // Minimum allowed value is 1
      '#step' => 1, // Ensure integers only (no decimals)
      '#required' => TRUE,
      '#default' => $this->configuration['failures_before_allow'] ?? 1,
    ];

    return $this->setSettingsParents($form);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->configuration['country_field'] = $form_state->getValue('country_field');    
    $this->configuration['failed_to_validate_field'] = $form_state->getValue('failed_to_validate_field');    
    $this->configuration['failures_before_allow'] = $form_state->getValue('failures_before_allow');    
    $this->configuration['validation_failure_msg'] = $form_state->getValue('validation_failure_msg'); 
  }



  private function getStore(){
    return$this->tempStore->get($this->webform->id() . $this->getHandlerId());
  }
  private function storeData(string $key, string|int $data){
    try {
      $store = $this->getStore();
      $store->set($key, $data);
    } catch (TempStoreException $e){
      return;
    }
  }

  private function getData(string $key) : string|int|NULL {
    try {
      $store = $this->getStore();
      return $store->get($key);
    } catch (TempStoreException $e){
      return NULL;
    }
  }

  /**
   * Clear all data in this tempstore collection.
   */
  public function clearAllData() {
    $store = $this->getStore();
    $store->delete('previous_failures');
    $store->delete('previous_country');
  }


  public function submitForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {  
    $failed_to_validate_field =  $this->configuration['failed_to_validate_field'] ?? '';
    if (!empty($failed_to_validate_field)){
      $guessed_country = $this->ip2LocationAPI->getCountryName();
      $country_field = $this->configuration['country_field'];
      $submitted_country = $form_state->get($country_field) ?? '';
      $submission_data =$webform_submission->getData();      
      if (isset($submission_data[$failed_to_validate_field])) {
        $submission_data[$failed_to_validate_field] = $submitted_country !=  $guessed_country;
        $webform_submission->setData($submission_data);;
      }
      else {
        // Error and record as a note on the submission.
        $this->getLogger()->error($this->t("Failed to set validation status %key, not present on webform", ['%key' => $failed_to_validate_field]));
        $notes = $webform_submission->getNotes() ?? '';
        $notes .= ' Failed to set validation status: ';
        $notes .=  $submitted_country !=  $guessed_country ? 'Failed'  : 'Succeeded';
        $webform_submission->setNotes($notes);
      }      
    }    
    $this->clearAllData();
  }
 
  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    // We want to check the submitted country against a lookup
    // If it passes we pass validation.
    // If it fails and has failed before on the same country submitted we fail validation unless
    // We've perviously had failures_before_allow attempts where failures_before_allow is set in config. 
    //
    // Note to achieve the above we are setting values in this method.
    // This is not ideal - but the values we set are in the fields added by the handler.

    $country_field = $this->configuration['country_field'];
    $failures_before_allow = $this->configuration['failures_before_allow'];
    $failure_country_mismatch_str = $this->configuration['validation_failure_msg'] ?? $this->defaultValidationVsg;
    

    // Get the submitted country.
    $submitted_country = $form_state->getValue($country_field) ?? "";
    if (empty($submitted_country)){
       $form_state->setErrorByName($country_field, $this->t('Country field required.'));
    }
    $guessed_country = $this->ip2LocationAPI->getCountryName();


    // Check what happened last time.
    $previous_country = $this->getData('previous_country');
    $previous_failures = $this->getData('previous_failures') ?? 0;
    
    

    if ($guessed_country == $submitted_country){
      // Our guess matches the submitted - Pass validation.
      return;
    }

    // We should fail at this point unless we have had more failures than $failures_before_allow.
    if ($previous_country == $submitted_country) {      
      $failures = $previous_failures + 1;
      if ($failures > $failures_before_allow){
        // We've hit our max failures.
        // Pass Validation                
        return;
      }
      // Increment previous_failures set failure and return.
      $this->storeData('previous_failures', $failures);    
      $form_state->setErrorByName($country_field,$this->t($failure_country_mismatch_str, ['%value' => $guessed_country]));      
      return;
    }

    // We need to update the previous_country and reset previous failures field.
    $this->storeData('previous_failures', 1);
    $this->storeData('previous_country', $submitted_country);
    $form_state->setErrorByName($country_field,$this->t($failure_country_mismatch_str, ['%value' => $guessed_country]));
  }
}