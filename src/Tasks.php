<?php

namespace ThinkShout\RoboWordpress;

use Symfony\Component\Process\Process;

class Tasks extends \Robo\Tasks
{

  private $projectProperties;

  function __construct() {
    $this->projectProperties = $this->getProjectProperties();
  }

  /**
   * Initialize the project for the first time.
   *
   * @return \Robo\Result
   */
  public function init() {
    $git_repo = exec('basename `git rev-parse --show-toplevel`');

    // Remove instructions for creating a new repo, because we've got one now.
    $readme_contents = file_get_contents('README.md');
    $start_string = '### Initial build (new repo)';
    $end_string = '### Initial build (existing repo)';
    $from = $this->findAllTextBetween($start_string, $end_string, $readme_contents);

    $find_replaces = array(
      array(
        'source' => 'composer.json',
        'from' => '"name": "thinkshout/bedrock",',
        'to' => '"name": "thinkshout/' . $git_repo . '",',
      ),
      array(
        'source' => '.env.example',
        'from' => 'PROJECT="SITE"',
        'to' => 'PROJECT="' . $git_repo . '"',
      ),
      array(
        'source' => '.env.example',
        'from' => 'URL=http://example.com',
        'to' => 'URL=http://' . $git_repo . '',
      ),
      array(
        'source' => 'README.md',
        'from' => array($from, 'new-project-name'),
        'to' => array($end_string, $git_repo),
      ),
    );

    foreach ($find_replaces as $find_replace) {
      $this->taskReplaceInFile($find_replace['source'])
        ->from($find_replace['from'])
        ->to($find_replace['to'])
        ->run();
    }
  }

  /**
   * Generate configuration in your .env file.
   *
   * @arg array opts function options:
   *
   * @option string db-password Database password.
   * @option string db-user Database user.
   * @option string db-name Database name.
   * @option string db-host Database host.
   * @option string branch Branch.
   * @option string url Base URL for the Wordpress site.
   */
  function configure($opts = [
    'db-password' => NULL,
    'db-user' => NULL,
    'db-name' => NULL,
    'db-host' => NULL,
    'branch' => NULL,
    'url' => NULL,
  ]) {

    $allowed_opts = ['db-password', 'db-user', 'db-name', 'db-host', 'branch', 'url'];

    // Initialize the .env file
    $this->taskExec('wp dotenv init')
      ->option('with-salts')
      ->option('template=.env.example')
      ->option('force')
      ->run();

    // Loop through project properties and replace with command line arguments
    // if we have them.
    foreach ($allowed_opts as $opt) {
      if ($opts[$opt] !== NULL) {
        // Ugly method to allow an empty param to be passed for the password.
        if ($opts[$opt] == 'NULL') {
          $opts[$opt] = '';
        }
        $this->say("Test: ". strtoupper(str_replace('-', '_', $opt)));
        $this->projectProperties[$opt] = $opts[$opt];
        $this->taskExec('wp dotenv set')
          ->args([strtoupper(str_replace('-', '_', $opt)), $opts[$opt]])
          ->run();
      }
    }

    // Branch
    $branch = $this->projectProperties['branch'];

    // Terminus env
    $this->projectProperties['terminus_env'] = ($branch == 'master') ? 'dev' : $branch;
    $this->taskExec('wp dotenv set')
      ->args(['TERMINUS_ENV', $this->projectProperties['terminus_env']])
      ->run();

    // If branch was specified, write it out to the .env file for future runs.
    $this->taskExec('wp dotenv set')
      ->args(['BRANCH',  $branch])
      ->run();
  }

  /**
   * Perform git checkout of host files.
   */
  function deploy() {

    $repo = $this->projectProperties['host_repo'];

    $branch = $this->projectProperties['branch'];

    $webroot = $this->projectProperties['web_root'];

    $tmpDir = $this->getTmpDir();
    $hostDirName = $this->getFetchDirName();
    $this->stopOnFail();
    $fs = $this->taskFilesystemStack()
      ->mkdir($tmpDir)
      ->mkdir("$tmpDir/$hostDirName")
      ->run();

    // Make sure we have an empty temp dir.
    $this->taskCleanDir([$tmpDir])
      ->run();

    // Git checkout of the matching remote branch.
    $this->taskGitStack()
      ->stopOnFail()
      ->cloneRepo($repo, "$tmpDir/$hostDirName")
      ->dir("$tmpDir/$hostDirName")
      ->checkout($branch)
      ->run();

    // Get the last commit from the remote branch.
    $last_remote_commit = $this->taskExec('git log -1 --date=short --pretty=format:%ci')
      ->dir("$tmpDir/$hostDirName")
      ->run();
    $last_commit_date = trim($last_remote_commit->getMessage());

    $commit_message = $this->taskExec("git log --pretty=format:'%h %s' --no-merges --since='$last_commit_date'")->run()->getMessage();

    $commit_message = "Combined commits: \n" . $commit_message;

    // Copy webroot to our deploy directory.
    $this->taskRsync()
      ->fromPath("./")
      ->toPath("$tmpDir/deploy")
      ->args('-a', '-v', '-z', '--no-group', '--no-owner')
      ->excludeVcs()
      ->exclude('.gitignore')
      ->exclude('.env')
      ->printed(FALSE)
      ->run();

    // Move host .git into our deployment directory.
    $this->taskRsync()
      ->fromPath("$tmpDir/$hostDirName/.git")
      ->toPath("$tmpDir/deploy")
      ->args('-a', '-v', '-z', '--no-group', '--no-owner')
      ->printed(FALSE)
      ->run();

    $this->taskGitStack()
      ->stopOnFail()
      ->dir("$tmpDir/deploy")
      ->add('-A')
      ->commit($commit_message)
      ->push('origin', $branch)
//      ->tag('0.6.0')
//      ->push('origin','0.6.0')
      ->run();

    // Clean up
//    $this->taskDeleteDir($tmpDir)
//      ->run();


  }

  /**
   * Install or re-install the Drupal site.
   *
   * @return \Robo\Result
   */
  function install() {

    // Install dependencies. Only works locally.
    $this->taskComposerInstall()
      ->optimizeAutoloader()
      ->run();

    // Wipe the DB.
    $this->_exec('wp db reset --yes');

    $password = bin2hex(random_bytes(10));

    // Run the installation.
    $result = $this->taskExec('wp core install')
      ->option('url="' . $this->projectProperties['url'] . '"')
      ->option('title="' . $this->projectProperties['project'] . '"')
      ->option('admin_user="' . $this->projectProperties['project'] . '_admin"')
      ->option('admin_password="' . $password . '"')
      ->option('admin_email="dev-team+' . $this->projectProperties['project'] . '@thinkshout.com"')
      ->option('skip-email')
      ->run();

    if ($result->wasSuccessful()) {
      $this->say('Install complete');
      $this->say('Admin: ' . $this->projectProperties['project'] . '_admin');
      $this->say('Password: ' . $password);
    }

    return $result;
  }

  /**
   * Output PHP info.
   */
  function info() {
    phpinfo();
  }

  /**
   * Run tests for this site. Currently just Behat.
   *
   * @option string feature Single feature file to run.
   *   Ex: --feature=features/user.feature.
   * @option string profile which behat profile to run.
   *   Ex: --profile default, --profile local, --profile ci
   *
   * @return \Robo\Result
   */
  function test($opts = ['feature' => NULL, 'profile' => 'local']) {
    $behat_cmd = $this->taskExec('behat')
      ->arg('--config behat/behat.' . $opts['profile'] . '.yml')
      ->arg(' --profile ' . $opts['profile'])
      ->arg(' --format progress');

    if ($opts['feature']) {
      $behat_cmd->arg($opts['feature']);
    }

    $behat_result = $behat_cmd->run();

    return $behat_result;

    // @TODO consider adding unit tests back in. These are slow and aren't working great right now.
//    $unit_result = $this->taskPHPUnit('../vendor/bin/phpunit')
//      ->dir('core')
//      ->run();
//
//    // @TODO will need to address multiple results when we enable other tests as well.
//    return $behat_result->merge($unit_result);
  }

  /**
   * Run php's built in webserver at localhost:PORT.
   *
   * @option int port Port number of listen on. Defaults to 8088.
   */
  function run($opts = ['port' => 8088]) {
    // execute server in background
    $this->taskServer($opts['port'])
      ->background()
      ->run();
  }

  /**
   * Prepare a Pantheon multidev for this project/branch.
   *
   * @option boolean install Trigger an install on Pantheon.
   * @option boolean y Answer prompts with y.
   *
   * @return \Robo\Result
   */
  function pantheonDeploy($opts = ['install' => FALSE, 'y' => FALSE]) {
    $terminus_env = $this->projectProperties['terminus_env'];
    $result = $this->taskExec('terminus site environment-info')->run();

    // Check for existing multidev and prompt to create.
    if (!$result->wasSuccessful()) {
      if (!$opts['y']) {
        if (!$this->confirm('No matching multidev found. Create it?')) {
          return FALSE;
        }
      }
      $this->taskExec("terminus site create-env --to-env=$terminus_env --from-env=dev")
        ->run();
    }

    // Make sure our site is awake.
    $this->_exec('terminus site wake');

    // Ensure we're in git mode.
    $this->_exec('terminus site set-connection-mode --mode=git');

    // Deployment
    $this->deploy();

    // Trigger remote install.
    if ($opts['install']) {
      $this->_exec('terminus site wipe --yes');
      return $this->pantheonInstall();
    }
  }

  /**
   * Install site on Pantheon.
   *
   * @return \Robo\Result
   */
  function pantheonInstall() {

    // Get the current branch using the simple exec command.
    $command = 'terminus site hostnames list | awk \'{if (NR!=1) print $1}\'';
    $process = new Process($command);
    $process->setTimeout(NULL);
//    $process->setWorkingDirectory($properties['working_dir']);
    $process->run();

    $url = trim($process->getOutput());

    // Wipe the existing site.
    $this->taskExec('terminus site wipe')
      ->option('yes')
      ->run();

    // Generate a "random" password.
    $password = bin2hex(random_bytes(10));

    // @TODO use the correct URL for the environment as provided by terminus.
    $install_cmd = 'terminus wp \'core install --url="' . $url . '"' .
                   ' --title="' . $this->projectProperties['project'] . '"' .
                   ' --admin_user="' . $this->projectProperties['project'] . '_admin"' .
                   ' --admin_user="' . $this->projectProperties['project'] . '_admin"' .
                   ' --admin_password="' . $password . '"' .
                   ' --admin_email="dev-team+' . $this->projectProperties['project'] . '@thinkshout.com"' .
                   ' --skip-email' .
                   '\'';
    // Run the installation.
    $result = $this->taskExec($install_cmd)
                   ->run();

    if ($result->wasSuccessful()) {
      $this->say('Install complete');
      $this->say('Admin: ' . $this->projectProperties['project'] . '_admin');
      $this->say('Password: ' . $password);
    }

    if ($result->wasSuccessful()) {
      $this->say('Install complete');
    }

    return $result;
  }

  /**
   * Run tests against the Pantheon multidev.
   *
   * @option string feature Single feature file to run.
   *   Ex: --feature=features/user.feature.
   *
   * @return \Robo\Result
   */
  function pantheonTest($opts = ['feature' => NULL]) {
    $project = $this->projectProperties['project'];
    $env = $this->projectProperties['branch'];
    $url = "https://$env-$project.pantheonsite.io";
    $alias = "pantheon.$project.$env";
    $drush_param = '"alias":"' . $alias . '"';

    $root = $this->projectProperties['web_root'];

    // Add the specific behat config to our environment.
    putenv('BEHAT_PARAMS={"extensions":{"Behat\\\\MinkExtension":{"base_url":"' . $url . '"},"Drupal\\\\DrupalExtension":{"drupal":{"drupal_root":"' . $root . '"},"drush":{' . $drush_param . '}}}}');

    return $this->test(['profile' => 'pantheon', 'feature' => $opts['feature']]);
  }

  protected function getProjectProperties() {

    $properties = ['project' => '', 'host_repo' => '', 'url' => '', 'terminus_env' => ''];

    $properties['working_dir'] = getcwd();

    // Load .env file from the local directory if it exists. Or use the .env.dist
    $env_file = (file_exists($properties['working_dir'] . '/.env')) ? '.env' : '.env.example';

    $dotenv = new \Dotenv\Dotenv($properties['working_dir'], $env_file);
    $dotenv->load();

    array_walk($properties, function(&$var, $key) {
      $env_var = strtoupper($key);
      if ($value = getenv($env_var)) {
        $var = $value;
      }
    });

    if ($web_root = getenv('WEB_ROOT')) {
      $properties['web_root'] = $properties['working_dir'] . '/' . $web_root;
    }
    else {
      $properties['web_root'] = $properties['working_dir'];
    }

    $properties['escaped_web_root_path'] = $this->escapeArg($properties['web_root']);

    if (!isset($properties['branch'])) {
      // Get the current branch using the simple exec command.
      $command = 'git symbolic-ref --short -q HEAD';
      $process = new Process($command);
      $process->setTimeout(NULL);
      $process->setWorkingDirectory($properties['working_dir']);
      $process->run();

      $branch = $process->getOutput();

      $properties['branch'] = trim($branch);
    }

    if ($db_name = getenv('DB_NAME')) {
      $properties['db-name'] = $db_name;
    }
    else {
      $properties['db-name'] = $properties['project'] . '_' . $properties['branch'];
    }

    return $properties;
  }

  // See Symfony\Component\Console\Input.
  protected function escapeArg($string) {
    return preg_match('{^[\w-]+$}', $string) ? $string : escapeshellarg($string);
  }

  /**
   * Use regex to replace a 'key' => 'value', pair in a file like a settings file.
   *
   * @param $file
   * @param $key
   * @param $value
   */
  protected function replaceArraySetting($file, $key, $value) {
    $this->taskReplaceInFile($file)
      ->regex("/'$key' => '[^'\\\\]*(?:\\\\.[^'\\\\]*)*',/s")
      ->to("'$key' => '". $value . "',")
      ->run();
  }

  /**
   * Build temp folder path for the task.
   *
   * @return string
   */
  protected function getTmpDir() {
    return realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'wp-deploy-' . time();
  }

  /**
   * Decide what our fetch directory should be named
   * (temporary location to stash scaffold files before
   * moving them to their final destination in the project).
   *
   * @return string
   */
  protected function getFetchDirName() {
    return 'host';
  }

  /**
   * Finds the text between two strings within a third string.
   *
   * @param $beginning
   * @param $end
   * @param $string
   *
   * @return string
   *   String containing $beginning, $end, and everything in between.
   */
  private function findAllTextBetween($beginning, $end, $string) {
    $beginningPos = strpos($string, $beginning);
    $endPos = strpos($string, $end);
    if ($beginningPos === false || $endPos === false) {
      return '';
    }

    $textToDelete = substr($string, $beginningPos, ($endPos + strlen($end)) - $beginningPos);

    return $textToDelete;
  }

}