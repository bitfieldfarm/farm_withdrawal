<?php

namespace Drupal\farm_withdrawal\EventSubscriber;

use Drupal\log\Event\LogEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Copy the referenced material quantity material type meat withdrawal to the medical log.
 */
class LogEventSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      LogEvent::PRESAVE => 'logPresave',
      LogEvent::UPDATE => 'logUpdate',
    ];
  }

  /**
   * Perform actions on log presave.
   *
   * @param \Drupal\log\Event\LogEvent $event
   *   The log event.
   */
  public function logPresave(LogEvent $event) {
    $log = $event->log;

    // Bail if not a medical log, has no quantities, or already has a meat withdrawal value.
    if ($log->bundle() !== 'medical' || $log->get('quantity')->isEmpty() || !$log->get('meat_withdrawal')->isEmpty()) {
      return;
    }

    // Find the max meat withdrawal value defined by referenced material types.
    $max_meat_withdrawal = NULL;
    /** @var \Drupal\quantity\Entity\QuantityInterface $quantity */
    foreach ($log->get('quantity')->referencedEntities() as $quantity) {

      // Only check material quantities with material_type reference.
      if ($quantity->bundle() !== 'material' || $quantity->get('material_type')->isEmpty()) {
        return;
      }

      // If the quantity does not have a material type with a meat withdrawal, skip it.
      $material_types = $quantity->get('material_type')->referencedEntities();
      if ($material_type = reset($material_types)) {
        $referenced_meat_withdrawal = $material_type->get('meat_withdrawal')->first()->value;
        $max_meat_withdrawal = max($max_meat_withdrawal, $referenced_meat_withdrawal);
      }
    }

    // Update the meat withdrawal value on the medical log.
    $log->set('meat_withdrawal', $max_meat_withdrawal);

  }

  /**
   *
   */
  public function logUpdate(LogEvent $event) {
    $log = $event->log;
    $withdrawal = $log->get('meat_withdrawal');

    // Bail if not a medical log, is not done or has no withdrawal or assets.
    if ($log->bundle() !== 'medical' || $withdrawal->isEmpty() || $log->get('asset')->isEmpty() || $log->status->value == 'pending') {
      return;
    }

    // Settings variables.
    $calendar_id = \Drupal::config('farm_calendar_events.settings')->get('calendar_id');

    // Google api client.
    $google_api_client = \Drupal::entityTypeManager()->getStorage('google_api_client')->load(1);
    $googleService = \Drupal::service('google_api_client.client');
    $googleService->setGoogleApiClient($google_api_client);

    // Check for expired token.
    if ($googleService->googleClient->isAccessTokenExpired()) {
      // Refresh the access token using refresh token.
      try {
        $credentials = $googleService->getAccessTokenWithRefreshToken();
      }
      catch (Exception $e) {
        \Drupal::logger('farm_calendar')->error($e->getMessage());
      }
    }
    else {
      // Fetch Access Token.
      try {
        $credentials = $googleService->googleClient->getAccessToken();
      }
      catch (Exception $e) {
        \Drupal::logger('farm_calendar')->error($e->getMessage());
      }
    }

    $access_token = $credentials['access_token'];
    $bearer = "Bearer $access_token";

    // Create httpClient.
    $client = \Drupal::httpClient();

    $withdrawal_days = $log->get('meat_withdrawal')->first()->value;
    $timestamp = $log->timestamp->value;

    $dt = new \DateTime("@$timestamp");
    $start_date = $dt->format('Y-m-d');
    $dt2 = $dt->modify('+' . $withdrawal_days . ' days');
    $end_date = $dt2->format('Y-m-d');

    foreach ($log->get('asset')->referencedEntities() as $asset) {

      $referenced_asset = $asset->label();
      \Drupal::messenger()->addWarning(t("{$referenced_asset} Meat Withdrawal {$withdrawal_days} days. Ends {$end_date}"));

      // JSON.
      $json_data = json_encode([
        "end" => ["date" => $end_date],
        "start" => ["date" => $start_date],
        "description" => "{$referenced_asset} Meat Withdrawal {$withdrawal_days} days. Ends {$end_date}",
        "summary" => $referenced_asset,
      ]);

      try {
        // Sending POST Request with $json_data to external server.
        $request = $client->post("https://www.googleapis.com/calendar/v3/calendars/$calendar_id/events",
        [
          'body' => $json_data,
          'headers' =>
         ['Authorization' => $bearer],
         ['Accept' => "application/json"],
        ]);
        // Getting Response after JSON Decode.
        $response = json_decode($request->getBody());
        \Drupal::messenger()->addStatus(t("Log: $referenced_asset sent to calendar"));
      }
      // Catch http exceptions and log errors.
      catch (\Exception $e) {
        \Drupal::logger('farm_calendar')->error($e->getMessage());
        \Drupal::messenger()->addError(t("Log: $referenced_asset failed to add calendar event"));
      }
    }
  }

}
