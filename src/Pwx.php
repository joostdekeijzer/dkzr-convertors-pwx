<?php
namespace DKZR\Convertors;

class Pwx {
  static private $version = '1.0';

  static protected $skip_zero = true;

  static function toGpx( string $xml_string, array $options = [] ) {
    $options = array_merge( [ 'skip_zero' => self::$skip_zero ], $options );

    $pwx = simplexml_load_string( $xml_string );

    if ( ! isset( $pwx->workout->sample ) ) {
      return;
    }

    $gpx = simplexml_load_string( '<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1"
  creator="DKZR/Convertors/PwxToGpx"
  xmlns="http://www.topografix.com/GPX/1/1"
  xmlns:gpxtpx="http://www.garmin.com/xmlschemas/TrackPointExtension/v1"
  xmlns:gpxx="http://www.garmin.com/xmlschemas/GpxExtensions/v3"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
>
  <metadata/>
  <trk>
    <name/>
    <trkseg/>
  </trk>
</gpx>' );

    $bounds = [
      'minlat' => 0,
      'minlon' => 0,
      'maxlat' => 0,
      'maxlon' => 0,
    ];

    $pwx_time_base = strtotime( $pwx->workout->time );
    $gpx->metadata->addChild( 'time', gmdate( 'c', $pwx_time_base ) );

    $name = '';
    if ( isset( $pwx->workout->athlete->name ) && trim( $pwx->workout->athlete->name ) && 'unknown' != trim( $pwx->workout->athlete->name ) ) {
      $name = trim( $pwx->workout->athlete->name );
    }

    if ( isset( $pwx->workout->sportType ) && trim( $pwx->workout->sportType ) ) {
      $name .= ( $name ? ' ' : '' ) . trim( $pwx->workout->sportType );
    }

    $name .= sprintf( ( $name ? ' (%s)' : '%s' ), gmdate( 'c', $pwx_time_base ) );
    $gpx->trk->name = $name;

    foreach ( $pwx->workout->sample as $sample ) {
      $time = gmdate( 'c', $pwx_time_base + floatval( $sample->timeoffset ) );
      $lat = isset( $sample->lat ) ? floatval( $sample->lat ) : null;
      $lon = isset( $sample->lon ) ? floatval( $sample->lon ) : null;
      $ele = isset( $sample->alt ) ? floatval( $sample->alt ) : null;

      $bounds['minlat'] = min( $bounds['minlat'], $lat );
      $bounds['minlon'] = min( $bounds['minlon'], $lon );
      $bounds['maxlat'] = max( $bounds['maxlat'], $lat );
      $bounds['maxlon'] = max( $bounds['maxlon'], $lon );

      if ( $options['skip_zero'] && ( ! $lat || ! $lon ) ) {
        continue;
      }

      $trkpt = $gpx->trk->trkseg->addChild( 'trkpt' );

      $trkpt->addAttribute( 'lat', $lat );
      $trkpt->addAttribute( 'lon', $lon );
      $trkpt->addChild( 'time', $time );

      if ( null !== $ele ) {
        $trkpt->addChild( 'ele', $ele );
      }
    }

    $b = $gpx->metadata->addChild( 'bounds' );
    $b->addAttribute( 'minlat', $bounds['minlat']);
    $b->addAttribute( 'minlon', $bounds['minlon']);
    $b->addAttribute( 'maxlat', $bounds['maxlat']);
    $b->addAttribute( 'maxlon', $bounds['maxlon']);

    return $gpx->asXML();
  }

  static function toTcx( string $xml_string, array $options = [] ) {
    $options = array_merge( [ 'skip_zero' => self::$skip_zero ], $options );

    $pwx = simplexml_load_string( $xml_string );

    if ( ! isset( $pwx->workout->sample ) ) {
      return;
    }

    $tcx = simplexml_load_string( '<?xml version="1.0" encoding="UTF-8"?>
<TrainingCenterDatabase
  xsi:schemaLocation="http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2 http://www.garmin.com/xmlschemas/TrainingCenterDatabasev2.xsd"
  xmlns:ns5="http://www.garmin.com/xmlschemas/ActivityGoals/v1"
  xmlns:ns3="http://www.garmin.com/xmlschemas/ActivityExtension/v2"
  xmlns:ns2="http://www.garmin.com/xmlschemas/UserProfile/v2"
  xmlns="http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xmlns:ns4="http://www.garmin.com/xmlschemas/ProfileExtension/v1"
>
  <Activities/>
</TrainingCenterDatabase>' );

    $pwx_time_base = strtotime( $pwx->workout->time );

    $segments = [];
    if ( isset( $pwx->workout->segment ) ) {
      foreach ( $pwx->workout->segment as $segment ) {
        $segment->summarydata->beginning = floatval( $segment->summarydata->beginning );
        $segments[ ( $pwx_time_base + $segment->summarydata->beginning ) * 1000 ] = $segment;
      }
    }

    if ( floatval( $pwx->workout->sample[0]->timeoffset ) < floatval( $pwx->workout->segment[0]->summarydata->beginning ) ) {
        $segments[ ( $pwx_time_base + floatval( $pwx->workout->sample[0]->timeoffset ) ) * 1000 ] = (object) [
          'name' => '',
          'summarydata' => (object) [
            'beginning' => floatval( $pwx->workout->sample[0]->timeoffset ),
            'duration' => floatval( $pwx->workout->segment[0]->summarydata->beginning ) - floatval( $pwx->workout->sample[0]->timeoffset ),
          ],
        ];
    }

    ksort( $segments );

    $activity = $tcx->Activities->addChild( 'Activity' );
    $activity->addAttribute( 'Sport', ( isset( $pwx->workout->sportType ) && trim( $pwx->workout->sportType ) ? trim( $pwx->workout->sportType ) : '' ) );
    $activity->addChild( 'Id', gmdate( 'c', $pwx_time_base ));

    $current = reset( $segments );
    $next = next( $segments );

    $track = self::tcxCreateLap( $activity, $current, $pwx_time_base );

    foreach ( $pwx->workout->sample as $sample ) {
      $offset = floatval( $sample->timeoffset );
      $lat = isset( $sample->lat ) ? floatval( $sample->lat ) : null;
      $lon = isset( $sample->lon ) ? floatval( $sample->lon ) : null;
      $alt = isset( $sample->alt ) ? floatval( $sample->alt ) : null;

      if ( $options['skip_zero'] && ( ! $lat || ! $lon ) ) {
        continue;
      }

      if ( false !== $next && $offset >= $next->summarydata->beginning ) {
        $current = $next;
        $next = next( $segments );
        $track = self::tcxCreateLap( $activity, $current, $pwx_time_base );
      }

      $trackpoint = $track->addChild( 'Trackpoint' );
      $trackpoint->addChild( 'Time', gmdate( 'c', $pwx_time_base + $offset ) );

      $position = $trackpoint->addChild( 'Position' );
      $position->addChild( 'LatitudeDegrees', $lat );
      $position->addChild( 'LongitudeDegrees', $lon );

      if ( null !== $alt ) {
        $trackpoint->addChild( 'AltitudeMeters', $alt );
      }
    }

    return $tcx->asXML();
  }

  static private function tcxCreateLap( &$activity, $current, $pwx_time_base ) {
    $distance = ( isset( $current->summarydata->dist ) && floatval( $current->summarydata->dist ) ? floatval( $current->summarydata->dist ) : 0 );
    $calories = ( isset( $current->summarydata->work ) && floatval( $current->summarydata->work ) ? floatval( $current->summarydata->work ) / 4.1868 : 0 );

    $lab = $activity->addChild( 'Lap' );
    $lab->addAttribute( 'StartTime', gmdate( 'c', $pwx_time_base + $current->summarydata->beginning ) );
    $lab->addChild( 'TotalTimeSeconds', floatval( $current->summarydata->duration ) );
    $lab->addChild( 'DistanceMeters', $distance );
    $lab->addChild( 'Calories', $calories );
    $lab->addChild( 'Intensity', ( $distance || $calories ? 'Active' : 'Resting' ) );
    $lab->addChild( 'TriggerMethod', 'Manual' );

    return $lab->addChild( 'Track' );
  }
}
