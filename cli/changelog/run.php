#!/usr/bin/php
<?php
/**
 * An example command line application built on the Joomla Platform.
 *
 * To run this example, adjust the executable path above to suite your operating system,
 * make this file executable and run the file.
 *
 * Alternatively, run the file using:
 *
 * php -f run.php
 *
 * @package     Joomla.Examples
 * @copyright   Copyright (C) 2005 - 2011 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

// We are a valid Joomla entry point.
define('_JEXEC', 1);

// Setup the path related constants.
define('JPATH_BASE', dirname(__FILE__));

// Bootstrap the application.
require dirname(dirname(dirname(__FILE__))).'/bootstrap.php';

jimport('joomla.application.cli');

// Register the markdown parser class so it's loaded when needed.
JLoader::register('ElephantMarkdown', __DIR__.'/includes/markdown.php');

/**
 * Joomla Platform Changelog builder.
 *
 * This application builds the HTML version of the Joomla Platform change log from the Github API
 * that is used in news annoucements.
 *
 * @package     Joomla.Examples
 * @subpackage  Changlog
 * @since       1.0
 */
class Changelog extends JCli
{
	/**
	 * An array of output buffers.
	 *
	 * @var    array
	 * @since  11.4
	 */
	protected $buffers = array();

	/**
	 * Execute the application.
	 *
	 * @return  void
	 *
	 * @since   11.3
	 */
	public function execute()
	{
		// Import the JHttp class that will connect with the Github API.
		jimport('joomla.client.http');

		// Get a list of the merged pull requests.
		$merged = $this->getMergedPulls();

		// Set the maximum number of pages (and runaway failsafe).
		$cutoff = 20;
		$page = 1;

		// Check if we only want to get the latest version information.
		$latestOnly = $this->input->get('l');

		// Initialise the version cutoffs.
		$versions = array(
			0 => '11.4',
			310 => '11.3',
			140 => '11.2',
			72 => '11.1',
		);

		// Initialise arrays and metrics.
		$log = array();
		$userCount = array();
		$pullCount = 0;
		$mergedBy = array();
		$labelled = array();

		// Set the current version.
		$version = $versions[0];

		while ($cutoff--)
		{
			// Get a page of issues.
			$issues = $this->getIssues($page++);

			// Check if we've gone past the last page.
			if (empty($issues))
			{
				break;
			}

			// Loop through each pull.
			foreach ($issues as $issue)
			{
				// Check if the issue has been merged.
				if (empty($issue->pull_request->html_url))
				{
					continue;
				}
				// Check if the pull has been merged.
				if (!in_array($issue->number, $merged))
				{
					continue;
				}

				// Change the version
				if (isset($versions[$issue->number]))
				{
					// Populate buffers.
					$this->setBuffer("$version.userCount", $userCount);
					$this->setBuffer("$version.pullCount", $pullCount, false);
					$this->setBuffer("$version.mergedBy", $mergedBy);
					$this->setBuffer("$version.labelled", $labelled);

					// Reset counters.
					$pullCount = 0;
					$userCount = array();
					$mergedBy = array();
					$labelled = array();

					// Break if we only want the latest version.
					if ($latestOnly)
					{
						break 2;
					}

					// Increment version.
					$version = $versions[$issue->number];
				}

				// Check if the issue is labelled.
				foreach ($issue->labels as $label)
				{
					if (!isset($labelled[$label->name]))
					{
						$labelled[$label->name] = array();
					}
					$labelled[$label->name][] = '<a href="'.$issue->html_url.'">' . $issue->title . '</a>';
				}

				// Prepare the link to the pull.
				$html = '[<a href="'.$issue->html_url.'" title="Closed '.$issue->closed_at.'">';
				$html .= '#'.$issue->number;
				$html .= '</a>] <strong>'.$issue->title.'</strong>';
				$html .= ' (<a href="https://github.com/'.$issue->user->login.'">'.$issue->user->login.'</a>)';

				if (trim($issue->body))
				{
					// Parse the markdown formatted description of the pull.
					// Note, this doesn't account for all the Github flavoured markdown, but it's close.
					$html .= ElephantMarkdown::parse($issue->body);
				}

				$this->setBuffer("$version.log", $html);

				if (!isset($userCount[$issue->user->login]))
				{
					$userCount[$issue->user->login] = 0;
				}
				$userCount[$issue->user->login]++;
				$pullCount++;

				// Get specific information about the pull request.
				$data = $this->getPull($issue->number);
				if (!isset($mergedBy[$data->merged_by->login]))
				{
					$mergedBy[$data->merged_by->login] = 0;
				}
				$mergedBy[$data->merged_by->login]++;
			}
		}

		// Check if the output folder exists.
		if (!is_dir('./docs'))
		{
			mkdir('./docs');
		}

		// Write the file.
		file_put_contents('./docs/changelog.html', $this->render($latestOnly ? array($versions[0]) : $versions));

		// Close normally.
		$this->close();
	}

	/**
	 * Gets a named buffer.
	 *
	 * @param   string  $name  the name of the buffer.
	 *
	 * @return  string
	 *
	 * @since   11.4
	 */
	protected function getBuffer($name)
	{
		if (isset($this->buffers[$name]))
		{
			return $this->buffers[$name];
		}
		else
		{
			return '';
		}
	}

	/**
	 * Get a page of issue data.
	 *
	 * @param   integer  $page  The page number.
	 *
	 * @return  array
	 *
	 * @since   11.3
	 */
	protected function getIssues($page)
	{
		$this->out(sprintf('Getting issues page #%02d.', $page));

		$http = new JHttp;
		$r = $http->get(
			'https://api.github.com/repos/joomla/joomla-platform/issues?state=closed&sort=updated&direction=desc&page='.$page.'&per_page=100'
		);

		return json_decode($r->body);
	}

	/**
	 * Gets a list of the pull request numbers that have been merged.
	 *
	 * @return  array
	 *
	 * @since   11.3
	 */
	protected function getMergedPulls()
	{
		$cutoff = 20;
		$page = 1;
		$merged = array();

		while ($cutoff--)
		{
			$this->out(sprintf('Getting merged pulls page #%02d.', $page));

			$r = file_get_contents(
				'https://api.github.com/repos/joomla/joomla-platform/pulls?state=closed&page='.$page++.'&per_page=100'
			);

			$pulls = json_decode($r);

			// Check if we've gone past the last page.
			if (empty($pulls))
			{
				break;
			}

			// Loop through each of the pull requests.
			foreach ($pulls as $pull)
			{
				// If merged, add to the white list.
				if ($pull->merged_at)
				{
					$merged[] = $pull->number;
				}
			}
		}

		return $merged;
	}

	/**
	 * Get information about an individual pull request.
	 *
	 * @param   integer  $id  The pull id.
	 *
	 * @return  string
	 *
	 * @since   11.3
	 */
	protected function getPull($id)
	{
		$this->out('Getting info for pull '.(int) $id);
		$http = new JHttp;

		$r = file_get_contents('https://api.github.com/repos/joomla/joomla-platform/pulls/'.(int) $id);

		return json_decode($r);
	}

	/**
	 * Renders the output.
	 *
	 * @param   array  $versions  An array of the versions to render.
	 *
	 * @return  string
	 *
	 * @since   11.4
	 */
	protected function render(array $versions)
	{
		$html = '<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
		<html>
			<head>
				<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
				<title>Joomla Platform pull request log</title>
				</head>
			<body>';

		foreach ($versions as $version)
		{
			// Print the version number.
			$html .= PHP_EOL.'	<h1>'.$version.'</h1>';

			// Print out the labelled version of the changelog first.
			$labelled = $this->getBuffer("$version.labelled");
			if ($labelled)
			{
				foreach ($labelled as $label => $links)
				{
					$html .= PHP_EOL . "<h2>$label</h2>";
					$html .= PHP_EOL . '<ul>';

					foreach ($links as $link)
					{
						$html .= PHP_EOL . "<li>$link</li>";
					}

					$html .= PHP_EOL . '</ul>';
				}
			}

			// Print out the detailed version of the changelog.
			$log = $this->getBuffer("$version.log");
			$html .= PHP_EOL . '<h2>The following pull requests made by community contributors were merged:</h2>';
			$html .= PHP_EOL . '<ol>';
			foreach ($log as $issue)
			{
				$html .= PHP_EOL . "<li>$issue</li>";
			}
			$html .= PHP_EOL . '</ol>';

			// Print out the user-pull statistics.
			$userCount = $this->getBuffer("$version.userCount");
			arsort($userCount);
			$pullCount = $this->getBuffer("$version.pullCount");

			$html .= PHP_EOL.sprintf('<h4>%d pull requests.</h4>', $pullCount);
			$html .= PHP_EOL.'<ol>';
			foreach ($userCount as $user => $count)
			{
				$html .= PHP_EOL.sprintf('<li>%s: %d</li>', $user, $count);
			}
			$html .= PHP_EOL.'	</ol>';

			// Print out the admin-merge statistics.
			$mergedBy = $this->getBuffer("$version.mergedBy");
			arsort($mergedBy);

			$html .= PHP_EOL.'<h4>Merged by:</h4>';
			$html .= PHP_EOL.'<ol>';
			foreach ($mergedBy as $user => $count)
			{
				$html .= PHP_EOL.sprintf('<li>%s: %d</li>', $user, $count);
			}
			$html .= PHP_EOL.'</ol>';
		}

		$html .= PHP_EOL.'</body>';
		$html .= PHP_EOL.'</html>';

		return $html;
	}

	/**
	 * Sets a named buffer.
	 *
	 * @param   string   $name    The name of the buffer.
	 * @param   mixed    $text    The text to put into/append to the buffer.
	 * @param   boolean  $append  Append to the array buffer.
	 *
	 * @return  void
	 *
	 * @since   11.4
	 */
	protected function setBuffer($name, $text, $append = true)
	{
		if (!isset($this->buffers[$name]))
		{
			$this->buffers[$name] = array();
		}

		if (is_array($text) || !$append)
		{
			$this->buffers[$name] = $text;
		}
		else
		{
			$this->buffers[$name][] = $text;
		}
	}
}

// Catch any exceptions thrown.
try
{
	JCli::getInstance('Changelog')->execute();
}
catch (Exception $e)
{
	// An exception has been caught, just echo the message.
	fwrite(STDOUT, $e->getMessage() . "\n");
	exit($e->getCode());
}
