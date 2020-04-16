<?php
/**
 * Create command
 *
 * @package  wpsnapshots
 */

namespace WPSnapshots\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use WPSnapshots\RepositoryManager;
use WPSnapshots\WordPressBridge;
use WPSnapshots\Utils;
use WPSnapshots\Snapshot;
use WPSnapshots\Log;

/**
 * The create command creates a snapshot in the .wpsnapshots directory but does not push it remotely.
 */
class Create extends Command {

	/**
	 * Setup up command
	 */
	protected function configure() {
		$this->setName( 'create' );
		$this->setDescription( 'Create a snapshot locally.' );
		$this->addOption( 'exclude', false, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exclude a file or directory from the snapshot.' );
		$this->addOption( 'exclude_uploads', false, InputOption::VALUE_NONE, 'Exclude uploads from pushed snapshot.' );
		$this->addOption( 'repository', null, InputOption::VALUE_REQUIRED, 'Repository to use. Defaults to first repository saved in config.' );
		$this->addOption( 'no_scrub', false, InputOption::VALUE_NONE, "Don't scrub personal user data." );
		$this->addOption( 'small', false, InputOption::VALUE_NONE, 'Trim data and files to create a small snapshot. Note that this action will modify your local.' );
		$this->addOption( 'include_files', null, InputOption::VALUE_NONE, 'Include files in snapshot.' );
		$this->addOption( 'include_db', null, InputOption::VALUE_NONE, 'Include database in snapshot.' );

		$this->addOption( 'slug', null, InputOption::VALUE_REQUIRED, 'Project slug for snapshot.' );
		$this->addOption( 'description', null, InputOption::VALUE_OPTIONAL, 'Description of snapshot.' );

		$this->addOption( 'path', null, InputOption::VALUE_REQUIRED, 'Path to WordPress files.' );
		$this->addOption( 'db_host', null, InputOption::VALUE_REQUIRED, 'Database host.' );
		$this->addOption( 'db_name', null, InputOption::VALUE_REQUIRED, 'Database name.' );
		$this->addOption( 'db_user', null, InputOption::VALUE_REQUIRED, 'Database user.' );
		$this->addOption( 'db_password', null, InputOption::VALUE_REQUIRED, 'Database password.' );
	}

	/**
	 * Executes the command
	 *
	 * @param  InputInterface  $input Command input
	 * @param  OutputInterface $output Command output
	 */
	protected function execute( InputInterface $input, OutputInterface $output ) {
		Log::instance()->setOutput( $output );

		$repository = RepositoryManager::instance()->setup( $input->getOption( 'repository' ) );

		if ( ! $repository ) {
			Log::instance()->write( 'Could not setup repository.', 0, 'error' );
			return 1;
		}

		$path = $input->getOption( 'path' );

		if ( empty( $path ) ) {
			$path = getcwd();
		}

		$path = Utils\normalize_path( $path );

		$helper = $this->getHelper( 'question' );

		$project = $input->getOption( 'slug' );

		if ( ! empty( $project ) ) {
			$project = preg_replace( '#[^a-zA-Z0-9\-_]#', '', $project );
		}

		if ( empty( $project ) ) {
			$project_question = new Question( 'Project Slug (letters, numbers, _, and - only): ' );
			$project_question->setValidator( '\WPSnapshots\Utils\slug_validator' );

			$project = $helper->ask( $input, $output, $project_question );
		}

		$description = $input->getOption( 'description' );

		if ( ! isset( $description ) ) {
			$description_question = new Question( 'Snapshot Description (e.g. Local environment): ' );
			$description_question->setValidator( '\WPSnapshots\Utils\not_empty_validator' );

			$description = $helper->ask( $input, $output, $description_question );
		}

		$exclude = $input->getOption( 'exclude' );

		if ( ! empty( $input->getOption( 'exclude_uploads' ) ) ) {
			$exclude[] = './uploads';
		}

		if ( empty( $input->getOption( 'include_files' ) ) ) {
			$files_question = new ConfirmationQuestion( 'Include files in snapshot? (yes|no) ', true );

			$include_files = $helper->ask( $input, $output, $files_question );
		} else {
			$include_files = true;
		}

		if ( empty( $input->getOption( 'include_db' ) ) ) {
			$db_question = new ConfirmationQuestion( 'Include database in snapshot? (yes|no) ', true );

			$include_db = $helper->ask( $input, $output, $db_question );
		} else {
			$include_db = true;
		}

		if ( empty( $include_files ) && empty( $include_db ) ) {
			Log::instance()->write( 'A snapshot must include either a database or a snapshot.', 0, 'error' );
			return 1;
		}

		$snapshot = Snapshot::create(
			[
				'db_host'        => $input->getOption( 'db_host' ),
				'db_name'        => $input->getOption( 'db_name' ),
				'db_user'        => $input->getOption( 'db_user' ),
				'db_password'    => $input->getOption( 'db_password' ),
				'project'        => $project,
				'path'           => $path,
				'description'    => $description,
				'no_scrub'       => $input->getOption( 'no_scrub' ),
				'small'          => $input->getOption( 'small' ),
				'exclude'        => $exclude,
				'repository'     => $repository->getName(),
				'contains_db'    => $include_db,
				'contains_files' => $include_files,
			], $output, $input->getOption( 'verbose' )
		);

		if ( is_a( $snapshot, '\WPSnapshots\Snapshot' ) ) {
			Log::instance()->write( 'Create finished! Snapshot ID is ' . $snapshot->id, 0, 'success' );
		} else {
			return 1;
		}
	}
}
