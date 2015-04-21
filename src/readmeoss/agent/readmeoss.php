<?php
/*
 * Author: Daniele Fognini
 * Copyright (C) 2014-2015, Siemens AG
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * version 2 as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

define("README_AGENT_NAME", "readmeoss");

use Fossology\Lib\Agent\Agent;
use Fossology\Lib\Dao\UploadDao;
use Fossology\Lib\Report\LicenseClearedGetter;
use Fossology\Lib\Report\XpClearedGetter;

include_once(__DIR__ . "/version.php");

class ReadmeOssAgent extends Agent
{
  const UPLOAD_ADDS = "uploadsAdd";
  /** @var LicenseClearedGetter  */
  private $licenseClearedGetter;
  /** @var XpClearedGetter */
  private $cpClearedGetter;
  /** @var UploadDao */
  private $uploadDao;
  /** @var int[] */ 
  protected $additionalUploadIds = array();
  
  function __construct()
  {
    $this->cpClearedGetter = new XpClearedGetter("copyright", "statement", false, "content ilike 'Copyright%'");
    $this->licenseClearedGetter = new LicenseClearedGetter();
    
    $this->schedulerHandledLongOpts[] = self::UPLOAD_ADDS.':';
    parent::__construct(README_AGENT_NAME, AGENT_VERSION, AGENT_REV);
    
    $this->uploadDao = $this->container->get('dao.upload');
  }
  
  /**
   * @overwrite
   */
  function scheduler_connect()
  {
    $args = getopt($this->schedulerHandledOpts, $this->schedulerHandledLongOpts);

    $this->schedulerMode = array_key_exists("scheduler_start", $args);
    $this->additionalUploadIds = array_key_exists(self::UPLOAD_ADDS,$args) ? explode(',',$args[self::UPLOAD_ADDS]) : array();
    $this->userId = $args['userID'];
    $this->groupId = $args['groupID'];
    $this->jobId = $args['jobId'];

    $this->initArsTable();

    if ($this->schedulerMode)
    {
      $this->scheduler_greet();

      pcntl_signal(SIGALRM, function($signo) { Agent::heartbeat_handler($signo); });
      pcntl_alarm(ALARM_SECS);
    }
  }
  

  /**
   * @todo without wrapper
   */
  function processUploadId($uploadId)
  {
    $groupId = $this->groupId;

    $this->heartbeat(0);
    $licenses = $this->licenseClearedGetter->getCleared($uploadId, $groupId);
    $licenseStmts = $licenses['statements'];
    $this->heartbeat(count($licenseStmts));
    $copyrights = $this->cpClearedGetter->getCleared($uploadId, $groupId);
    $copyrightStmts = $copyrights['statements'];
    $this->heartbeat(count($copyrightStmts));

    foreach($this->additionalUploadIds as $addUploadId)
    {
      $moreLicenses = $this->licenseClearedGetter->getCleared($addUploadId, $groupId);
      $licenseStmts = array_merge($licenseStmts, $moreLicenses['statements']);
      $this->heartbeat(count($moreLicenses['statements']));
      $moreCopyrights = $this->cpClearedGetter->getCleared($addUploadId, $groupId);
      $copyrightStmts = array_merge($copyrightStmts, $moreCopyrights['statements']);
      $this->heartbeat(count($moreCopyrights['statements']));
    }

    $contents = array('licenses'=>$licenseStmts, 'copyrights'=>$copyrightStmts );
    $this->writeReport($contents, $uploadId);

    return true;
  }

  private function writeReport($contents, $uploadId)
  {
    global $SysConf;

    $packageName = $this->uploadDao->getUpload($uploadId)->getFilename();

    $fileBase = $SysConf['FOSSOLOGY']['path']."/report/";
    $fileName = $fileBase. "ReadMe_OSS_".$packageName.'_'.time().".txt" ;

    
    foreach($this->additionalUploadIds as $addUploadId)
    {
      $packageName .= ', ' . $this->uploadDao->getUpload($addUploadId)->getFilename();
    }
    
    
    if(!is_dir($fileBase)) {
      mkdir($fileBase, 0777, true);
    }
    umask(0133);
    $message = $this->generateReport($contents, $packageName);

    file_put_contents($fileName, $message);

    $this->updateReportTable($uploadId, $this->jobId, $fileName);
  }

  private function updateReportTable($uploadId, $jobId, $filename){
    $this->dbManager->getSingleRow("INSERT INTO reportgen(upload_fk, job_fk, filepath) VALUES($1,$2,$3)", array($uploadId, $jobId, $filename), __METHOD__);
  }

  private function generateReport($contents, $packageName)
  {
    $separator1 = "=======================================================================================================================";
    $separator2 = "-----------------------------------------------------------------------------------------------------------------------";
    $break = "\r\n\r\n";

    $output = $separator1 . $break . $packageName . $break;
    foreach($contents['licenses'] as $licenseStatement){
      $output .= $licenseStatement['text'] . $break;
      $output .= $separator2 . $break;
    }
    $copyrights = "";
    foreach($contents['copyrights'] as $copyrightStatement){
      $copyrights .= $copyrightStatement['content']."\r\n";
    }
    if(empty($copyrights)){
      $output .= "<Copyright notices>";
      $output .= $break;
      $output .= "<notices>";
    }else{
       $output .= "Copyright notices";
       $output .= $break; 
       $output .= $copyrights;
    }
    return $output;
  }

}

$agent = new ReadmeOssAgent();
$agent->scheduler_connect();
$agent->run_scheduler_event_loop();
$agent->scheduler_disconnect(0);
