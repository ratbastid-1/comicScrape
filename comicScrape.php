#!/usr/bin/php
<?php

require_once __DIR__.'/vendor/autoload.php';

use Cocur\Slugify\Slugify;

// Set up options (run with '-h' to get this all as nice help text)
$getopt = new \GetOpt\GetOpt([
    \GetOpt\Option::create('t', 'title', \GetOpt\GetOpt::MULTIPLE_ARGUMENT)
    	->setDescription('Provide one or more titles to run instead of using the configured titles file. Can appear more than once (I.e. -t "Walking Dead" -t "Saga")'),
    \GetOpt\Option::create('a', 'add')
    	->setDescription('In combination with -t, add these command-line titles to the titles file.' . "\n"),
    \GetOpt\Option::create('s', 'silent')
    	->setDescription('Suppress all command line output.'),
    \GetOpt\Option::create('v', 'verbose')
    	->setDescription('Put out lots of process and debugging output.' . "\n"),
    \GetOpt\Option::create('c', 'config', \GetOpt\GetOpt::REQUIRED_ARGUMENT)
    	->setDescription('Provide filename of an alternate config file.'),
    \GetOpt\Option::create('g', 'grep', \GetOpt\GetOpt::REQUIRED_ARGUMENT)
    	->setDescription('Search title file for the given string, and quit.'),
    \GetOpt\Option::create('m', 'mail', \GetOpt\GetOpt::REQUIRED_ARGUMENT)
    	->setDescription('Email command line output to an address.' . "\n"),
    \GetOpt\Option::create('h', 'help', \GetOpt\GetOpt::NO_ARGUMENT)
    	->setDescription('This help text.'),
]);
$getopt->process();
if ($getopt->getOption('help')) {
    echo $getopt->getHelpText();
    exit;
}

// get my file path (distinct from the user's cwd)
list($scriptPath) = get_included_files();
preg_match("/(.*\/)[^\/]+/", $scriptPath, $matches);
$filepath = $matches[1];


// configure ourselves with the command line options
$silent = $getopt->getOption('silent') ? true : false;
$GLOBALS['silent'] = $silent;
$verbose = $getopt->getOption('verbose') ? true : false;
$GLOBALS['verbose'] = $verbose;
$addTitle = $getopt->getOption('add') ? true : false;
$GLOBALS['addTitle'] = $addTitle;

$configfile = $getopt->getOption('config') ? $getopt->getOption('config') : 'scrapeConfig.json';
$configfilepath = $filepath . $configfile;
if (! is_readable($configfilepath) ) {
	echo "ERROR: Config file '$configfile' is not readable.\n";
	exit(0);
}
$config = json_decode(file_get_contents($configfilepath));



// Get our titles, from CLI or from file
$titles = $getopt->getOption('title');
$clititles = $titles ? true : false;
if ($addTitle && ! $clititles) {
	echo "ERROR: -a only functions in the context of a command-line title provided with -t\n";
	exit(0);
}
if (count($titles) == 0) {
	$titles = file($filepath . '/' . $config->titlesFile);
}

if ($getopt->getOption('grep')) {
	$result = preg_grep("/" . $getopt->getOption('grep') . "/i", $titles);
	
	if (count($result) > 0) {	
		$trimmed = [];
		foreach ($result as $hit) {
			$trimmed[] = rtrim(ltrim($hit));
		}
		echo "Found: " . implode(', ', $trimmed) . "\n";
	}
	else {
		echo "Not Found\n";
	}
	exit(0);	
}


// anti-explosion config, for when we download issues
ini_set('memory_limit', '10G');
//configure some ansi color globals:
$bold="\033[1m";
$regular="\033[0m";

$savedIssues = [];
foreach ($titles as $title) { //Go through titles from file

	// Handle -a argument, appending a CLI title to the titles file, if it's not already there.
	if ($clititles && $addTitle) {
		$existing = file($filepath . '/' . $config->titlesFile);
		$result = preg_grep('/^' . $title . "\n/", $existing);
		if (count($result) == 0) {
			file_put_contents($filepath . '/' . $config->titlesFile, $title . "\n", FILE_APPEND);
		}
	}
	// handle case where files have to be searched with different strings
	// than the download filename ("Once & Future" has to be searched "Once and Future")
	$searchterm = rtrim($title);
	if ( stristr($title, '|')) {
		[$searchterm, $filename] = explode('|', $title);
		$title = $filename ? $filename : $searchterm;
	}

	//Clean up filename, make directory if not exist
	$title = rtrim($title);
	$targetdir = $config->comicDirectory . '/' . $title;
	if (!file_exists($targetdir)) {
	    mkdir($targetdir, 0777, true);
	}

	//Look in the directory for existing files, find the highest-numbered one.
	$files = scandir($targetdir);
	
	$latestIssueOwned = 0;
	foreach (array_reverse($files) as $file) {
		if (preg_match("/$title\s+#?v?(\d+)/i", $file, $matches)) {
			$foundIssue = (Int)$matches[1];
			$latestIssueOwned = $foundIssue > $latestIssueOwned ? $foundIssue : $latestIssueOwned;
		}
	}
	if (!$silent) echo "$bold$title$regular - last issue saved is $latestIssueOwned\n";
	$wanted = $latestIssueOwned + 1;


	//collect up our search results page contents
	$pages = '';
	$maxpages = $config->maxPageDepth;
	if (!$silent) echo " Getting search page ";
	if ($config->onlyFirstPageForExistingSeries && $wanted != 1 ) {
		$maxpages = 1;
	}
	for ($i=1; $i <= $maxpages ; $i++) { 
		//Get whatever page we're on
		$pagebase = $config->siteUrl . "/page/$i";
		$searchurl = $pagebase . '/' . $config->queryFormat . urlencode($searchterm) ;
		if (!$silent) echo "$i... ";
		$pages  .= @file_get_contents($searchurl); 
			// Fun fact! "@" in front of this command suppresses errors. It's entirely
			// valid that one of our searches might not have a second or third page, and
			// we don't need to bug the user about that.

		if ($config->onlyFirstPageForExistingSeries) {
			// Let's check and see if our target issue is already caught--no point pulling
			// more pages if so.
			$slugify = new Slugify();
			$titleSlug = $slugify->slugify($searchterm);
			$targeturl = create_target_url($titleSlug, $wanted, $config);
			if (preg_match("/$targeturl/", $pages, $matches)) { 
				if (!$silent) echo "Found target issue, stopping.";
				break;
			}
		}

	}
	if (!$silent) echo "\n";

	$page = fopen("savedpage.txt", "w");
	fwrite($page, $pages);
	fclose($page);

	while(true) {
		// Clean up our inputs and define the issue slug we're going to searh for.
		$slugify = new Slugify();
		$titleSlug = $slugify->slugify($searchterm);

		$targeturl = create_target_url($titleSlug, $wanted, $config);
		//echo "Target url: $targeturl\n";


		if (preg_match("/$targeturl/", $pages, $matches)) { //Found it
			$issueurl = $matches[0];

			// Get the issue detail page
			$issuepage = file_get_contents($issueurl);
#			file_put_contents("issue.txt", $issuepage);

			// Search that page for the download link
			$targettag = '/href="([^"]+)"[^>]+title="Download Now"/';
			preg_match($targettag, $issuepage, $matches);
			$issueURL = $matches[1];
		
			if (! $issueURL) {

				// If we didn't get a download now link we'll try the Read Online
				//echo "\n\n$issuepage\n\n";


				$targettag = '/href="([^"]+)"[^>]+title="Read Online"/';
				preg_match($targettag, $issuepage, $matches);
				$readonlineURL = $matches[1];
				echo "Readonline url: $readonlineURL\n";
				$readonlinepage = file_get_contents($readonlineURL);
				$submatches = array();
				preg_match_all('/data-src=\' ([^ ]+) \'/', $readonlinepage, $submatches);
				
				$pageno = 0;
				if (count($submatches[1]) > 0) {
					foreach ($submatches[1] as $pageurl) {
						file_put_contents(++$pageno . ".jpg", file_get_contents($pageurl));
					}
					$fullpath = $config->comicDirectory . '/' . $title . '/' . $title  . ' '. sprintf("%03d", $wanted) . '.cbr';
					exec('rar a -ma4 \'' . $fullpath . '\' *.jpg; rm *.jpg');
					$savedIssues[] = $fullpath;

				}
				else {

					echo "Something strange about this download page; bailing on $titleSlug\n";
					break;
				}
				$wanted++;
				continue;
			}

			// Set up the download with good referer value (anti-antiscraping measure)
			$referer = $issueurl;
			$opts = array(
			       'http'=>array(
			           'header'=>array("Referer: $referer\r\n", "User-agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/87.0.4280.88 Safari/537.36\r\n")
			       )
			);
			$context = stream_context_create($opts);
			stream_context_set_params($context, array("notification" => "stream_notification_callback"));


			if (!$silent) echo "  Found issue $wanted. Downloading... \n";

			//Get the actual thing and save it.
			$issuebinary = file_get_contents($issueURL, false, $context);

			//Figure out where to save it (get filename from headers, or fall back to comicvine details)
			$filename = false;
			$filename = get_real_filename($http_response_header, $issueURL);
			if (!$filename) {
				if (!$silent) echo "\nError getting filename. Composing it on my own. ";
				$extension = is_zip($issuebinary) ? '.cbz' : '.cbr';
				$filename = $title . ' ' . sprintf("%03d", $wanted) . $extension;
	
			}
			if (!$silent) echo "\n";
			if (!$silent) echo "Saved: $filename\n";
			$fullpath = $config->comicDirectory . '/' . $title . '/' . $filename;
			$savedIssues[] = $fullpath;



			file_put_contents($fullpath, $issuebinary);

			// Set up flags and switches for next loop
			$wanted++;
			continue;
		}

		break; //We fell through here without finding a "next" issue, so bail.
	} //end while true
} //end foreach title

$summary = "Run Complete for config $configfile. \n" . count($savedIssues) . " downloaded issues.\n";
foreach ($savedIssues as $issuepath) {
	$summary .= " $issuepath\n";
}
if (!$silent) echo "\n------\n\n" . $summary;


if ($getopt->getOption('mail') ) {
	mail($getopt->getOption('mail'), "comicScrape results", $summary);
}


function get_real_filename($headers,$url)
{
	if ($GLOBALS['verbose']) print_r($headers);
    foreach($headers as $header)
    {
        if (strpos(strtolower($header),'location') !== false)
        {
            preg_match('/\/([^\/]+)$/', urldecode($header), $matches);
            return $matches[1];
        }
    }
}


function create_target_url($titleSlug, $wanted, $config) {
			// Clean up our inputs and define the issue slug we're going to searh for.
		$targeturl = $config->siteUrl . '/[^/]+/' . $titleSlug . '-' . $wanted . '-\d{4}';
		$targeturl = str_replace('/', '\/', $targeturl);
		#echo "Targeturl: $targeturl\n";
		return $targeturl;
}


function stream_notification_callback($notification_code, $severity, $message, $message_code, $bytes_transferred, $bytes_max) {
    static $filesize = null;
    $silent = $GLOBALS['silent'];
    $verbose = $GLOBALS['verbose'];

    switch($notification_code) {
    case STREAM_NOTIFY_RESOLVE:
    case STREAM_NOTIFY_AUTH_REQUIRED:
    case STREAM_NOTIFY_COMPLETED:
    case STREAM_NOTIFY_FAILURE:
    case STREAM_NOTIFY_AUTH_RESULT:
        /* Ignore */
        break;

    case STREAM_NOTIFY_REDIRECTED:
        if ($verbose) echo "Being redirected to: ", $message, "\n";
        break;

    case STREAM_NOTIFY_CONNECT:
        if ($verbose) echo "Connected...\n";
        break;

    case STREAM_NOTIFY_FILE_SIZE_IS:
        $filesize = $bytes_max;
        if ($verbose) echo "Filesize: ", $filesize, "\n";
        break;

    case STREAM_NOTIFY_MIME_TYPE_IS:
        if ($verbose) echo "Mime-type: ", $message, "\n";
        break;

    case STREAM_NOTIFY_PROGRESS:
        if ($bytes_transferred > 0) {
            if (!isset($filesize)) {
                if (!$silent) printf("\rUnknown filesize.. %2d kb done..", $bytes_transferred/1024);
            } else {
                $length = (int)(($bytes_transferred/$filesize)*100);
                if (!$silent) printf("\r[%-100s] %d%% (%2d/%2d kb)", str_repeat("=", $length). ">", $length, ($bytes_transferred/1024), $filesize/1024);
            }
        }
        break;
    }
}


function is_zip(String $data) {
    $sectors = explode("\x50\x4b\x01\x02", $data);
    return count($sectors) > 1 ? true : false;
}
