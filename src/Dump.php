<?php
/**
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author      Sergio Vaccaro
 * @copyright   2017 Istat
 * @license     http://opensource.org/licenses/GPL-3.0 GNU General Public License, version 3 (GPL-3.0)
 * @link        http://www.istat.it/
 * @version     1.0.0
 */

// Namespace
namespace GSC;

/**
 * This class manage the whole task
 *
 * @package     GSC
 * @api
 */
class Dump
{
    /**
     * Configuration
     *
     * @var array           The application configuration
     */
    protected $_config;

    /**
     * Storage
     *
     * @var \GSC\Storage    The storage system
     */
    protected $_storage;

    /**
     * Google timezone
     *
     * @var \DateTimeZone   The Google preferred timezone
     */
    protected $_googleTz;

    /**
     * Dump an exception and die
     *
     * NOTE Be careful when back to PHP 5: http://lt1.php.net/manual/en/function.set-exception-handler.php
     *
     * @param Throwable    The running exception
     */
    public function _exceptionHandler(\Throwable $e)
    {
        $message = $e->getMessage();

        // Stack trace file
        if (empty($stack_trace = $this->_config['main']['stack_trace'])) {
            $stack_trace = '/tmp/google_search_console_dump.stack_trace'; // Default value
        }

        if (file_put_contents($stack_trace, $e->__toString() . PHP_EOL)) {
            $message .= ' A complete stack trace is at ' . $this->_config['main']['stack_trace'];
        } else {
            $message .= ' Also, the cofnigured stack trace dump file is not writable.';
        }

        // Logging
        syslog(LOG_ERR, $message);

        // Immediately exit
        die($message . PHP_EOL);
    }

    /**
     * Build the dump object
     *
     * @param string $config_path       Configuration file path
     * @throw \Exception                When the configuration is incomplete
     */
    public function __construct($config_path)
    {
        // Not intended as web application
        ini_set('display_errors', 1);
        ini_set('html_errors', 0);

        // Read the configuration file
        if ( ! $this->_config = parse_ini_file($config_path, true)) {
            die('Unable to read the configuration file ' . $config_path);
        }

        // No sense if the site is not specified in configuration
        if (empty($this->_config['google']['site'])) {
            throw new \Exception('The google site MUST be defined in the configuration file.');
        }

        // Set an exception handler
        set_exception_handler(array($this, '_exceptionHandler'));

        // Google timezone
        $this->_googleTz = new \DateTimeZone('PST');

        // Initialize the storage
        if (empty($this->_config['storage'])) {
            $this->_config['storage'] = array('type' => 'csv'); // Default value
        }
        $this->_storage = new Storage($this->_config['storage']);
    }

    /**
     * Execute the dump
     *
     * @return integer                  The number of inserted days
     * @throw \Exception                When the storage is inconsistent
     */
    public function dump()
    {
        // Set the starting date
        if ($last = $this->_storage->lastDate()) {
            $start_date = new \DateTime($last . ' + 1 day', $this->_googleTz);
        } else {
            $start_date = new \DateTime('today -90 days', $this->_googleTz);
        }

        // Skip if the starting date is today, error if after today
        $today = new \DateTime('today', $this->_googleTz);
        $interval = $start_date->diff($today)->format('%r%a');
        if (0 == $interval) {
            syslog(LOG_NOTICE, 'The dump is already updated: skip.');
            return 0;
        } elseif (0 >= $interval) {
            throw new \Exception('The last database day is AFTER today.');
        }

        // Google API client initialization
        if (empty($this->_config['google']['secret'])) {
            $this->_config['google']['secret'] = 'secret.json'; // Default value
        }
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . realpath(__DIR__ . '/../' . $this->_config['google']['secret']));
        $client = new \Google_Client();
        $client->useApplicationDefaultCredentials();
        $client->addScope(\Google_Service_Webmasters::WEBMASTERS_READONLY);
        $service = new \Google_Service_Webmasters($client);
        $request = new \Google_Service_Webmasters_SearchAnalyticsQueryRequest();
        $request->setDimensions(array('query', 'page', 'country', 'device'));
        if (empty($this->_config['google']['limit'])) {
            $this->_config['google']['limit'] = 5000; // Default value
        }
        $request->setRowLimit($this->_config['google']['limit']);

        // Prepare the iteration parameters
        $date = $start_date;
        if (empty($this->_config['google']['max_days'])) {
            $this->_config['google']['max_days'] = 10; // Default value
        }
        $count = 0;
        $first = true;

        // Don't overload the poor Google
        if (empty($this->_config['google']['interval'])) {
            $this->_config['google']['interval'] = 2; // Default value
        }

        // Iterate!
        while (($count < $this->_config['google']['max_days']) && ($date->diff($today)->format('%r%a') > 0)) {
            if ( ! $first) {
                sleep($this->_config['google']['interval']);
            }

            // Prepare the query
            $date_as_string = $date->format('Y-m-d');
            $request->setStartDate($date_as_string);
            $request->setEndDate($date_as_string);

            // Query!
            $query = $service->searchanalytics->query($this->_config['google']['site'], $request);
            foreach($query->getRows() as $row) {
                $this->_storage->insert(array(
                    'date'          => $date_as_string,
                    'query'         => $row->keys[0],
                    'page'          => $row->keys[1],
                    'country'       => $row->keys[2],
                    'device'        => $row->keys[3],
                    'clicks'        => $row->clicks,
                    'impressions'   => $row->impressions,
                    'position'      => $row->position
                ));
            }

            syslog(LOG_INFO, 'Data for ' . $date_as_string . ' stored.');

            $date->add(new \DateInterval('P1D'));
            $count++;
        }

        return $count;
    }

    /**
     * Read the dump
     *
     * @param string $start_date    The start date, in a format accepted by strtotime()
     * @param string $end_date      The end date, in a format accepted by strtotime()
     * @param boolean $header      Return the table header as first row
     * @return array                The wanted table
     */
    public function read($start_date = 'today -30 days', $end_date = 'today -1 day', $header = false)
    {
        // Request dates are intended as local dates, not Google dates
        if (empty($this->_config['main']['timezone'])) {
            $this->_config['main']['timezone'] = 'UTC'; // Default value
        }
        $application_timezone = new \DateTimeZone($this->_config['main']['timezone']);

        // Convert request local dates to Google dates
        $start_date = new \DateTime($start_date, $application_timezone);
        $start_date->setTimezone($this->_googleTz);
        $end_date = new \DateTime($end_date, $application_timezone);
        $end_date->setTimezone($this->_googleTz);

        // Check if $end_date is consequent to $start_date
        $interval = $start_date->diff($end_date);
        if ('-' == $interval->format('%r')) {
            return array();
        }

        // Request!
        return $this->_storage->select($start_date->format('Y-m-d'), $end_date->format('Y-m-d'), $header);
    }
}
