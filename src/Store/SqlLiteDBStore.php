<?php

/**
 * @file
 * Class SqlLiteDBStore
 */

namespace Roomify\Bat\Store;

use Roomify\Bat\Event\Event;
use Roomify\Bat\Store\SqlDBStore;

/**
 * This is a SqlLite implementation of the Store.
 *
 */
class SqlLiteDBStore extends SqlDBStore {

  protected $pdo;

  public function __construct(\PDO $pdo, $event_type, $event_data = 'state') {
    parent::__construct($event_type, $event_data);

    $this->pdo = $pdo;
  }

  /**
   *
   * @param \DateTime $start_date
   * @param \DateTime $end_date
   * @param $unit_ids
   *
   * @return array
   */
  public function getEventData(\DateTime $start_date, \DateTime $end_date, $unit_ids) {

    $queries  = $this->buildQueries($start_date, $end_date, $unit_ids);

    $results = array();
    // Run each query and store results
    foreach ($queries as $type => $query) {
      $results[$type] = $this->pdo->query($query);
    }

    $db_events = array();

    // Cycle through day results and setup an event array
    foreach ($results[Event::BAT_DAY]->fetchAll() as $data) {
      // Figure out how many days the current month has
      $temp_date = new \DateTime($data['year'] . "-" . $data['month']);
      $days_in_month = (int)$temp_date->format('t');
      for ($i = 1; $i<=$days_in_month; $i++) {
        $db_events[$data['unit_id']][Event::BAT_DAY][$data['year']][$data['month']]['d' . $i] = $data['d'.$i];
      }
    }

    // With the day events taken care off let's cycle through hours
    foreach ($results[Event::BAT_HOUR]->fetchAll() as $data) {
      for ($i = 0; $i<=23; $i++) {
        $db_events[$data['unit_id']][Event::BAT_HOUR][$data['year']][$data['month']][$data['day']]['h'. $i] = $data['h'.$i];
      }
    }

    // With the hour events taken care off let's cycle through minutes
    foreach ($results[Event::BAT_MINUTE]->fetchAll() as $data) {
      for ($i = 0; $i<=59; $i++) {
        if ($i <= 9) {
          $index = 'm0'.$i;
        }
        else {
          $index = 'm'.$i;
        }
        $db_events[$data['unit_id']][Event::BAT_MINUTE][$data['year']][$data['month']][$data['day']][$data['hour']][$index] = $data[$index];
      }
    }

    return $db_events;
  }

  /**
   * @param \Roomify\Bat\Event\Event $event
   * @param $granularity
   *
   * @return bool
   */
  public function storeEvent(Event $event, $granularity = Event::BAT_HOURLY) {
    $stored = TRUE;

    try {
      // Itemize an event so we can save it
      $itemized = $event->itemizeEvent($granularity);

      // Write days
      foreach ($itemized[Event::BAT_DAY] as $year => $months) {
        foreach ($months as $month => $days) {
          $values = array_values($days);
          $keys = array_keys($days);
          $this->pdo->exec("INSERT OR REPLACE INTO $this->day_table (unit_id, year, month, " . implode(', ', $keys) . ") VALUES (" . $event->getUnitId() . ", $year, $month, " . implode(', ', $values) . ")");
        }
      }

      if ($granularity == Event::BAT_HOURLY) {
        // Write Hours
        foreach ($itemized[Event::BAT_HOUR] as $year => $months) {
          foreach ($months as $month => $days) {
            foreach ($days as $day => $hours) {
              // Count required as we may receive empty hours for granular events that start and end on midnight
              if (count($hours) > 0) {
                $values = array_values($hours);
                $keys = array_keys($hours);
                $this->pdo->exec("INSERT OR REPLACE INTO $this->hour_table (unit_id, year, month, day, " . implode(', ', $keys) . ") VALUES (" . $event->getUnitId() . ", $year, $month, " . substr($day, 1) . ", " . implode(', ', $values) . ")");
              }
            }
          }
        }

        // If we have minutes write minutes
        foreach ($itemized[Event::BAT_MINUTE] as $year => $months) {
          foreach ($months as $month => $days) {
            foreach ($days as $day => $hours) {
              foreach ($hours as $hour => $minutes) {
                $values = array_values($minutes);
                $keys = array_keys($minutes);
                $this->pdo->exec("INSERT OR REPLACE INTO $this->minute_table (unit_id, year, month, day, hour, " . implode(', ', $keys) . ") VALUES (" . $event->getUnitId() . ", $year, $month, " . substr($day, 1) . ", " . substr($hour, 1) . ", " . implode(', ', $values) . ")");
              }
            }
          }
        }
      }
    }
    catch (\Exception $e) {
      $saved = FALSE;
    }

    return $stored;
  }

}