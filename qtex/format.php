<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Provides import from and export to QuestionTeX, a LaTeX-based question
 * format.
 *
 * Supported question types are:
 *   - Multiple choice
 *   - Description
 *
 * For more information visit www.lemuren.math.ethz.ch
 *
 * @package questionbank
 * @subpackage importexport
 * @version Beta 6 (13.6.2011)
 * @author Leopold Talirz, Simon Knellwolf
 * @copyright 2011 Project LEMUREN, ETH Zurich
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @uses config.php
 * @uses format.php (included by ../../import.php)
 * @uses questionlib.php (included by ../../import.php)
 */
class qformat_qtex extends qformat_default{

    function provide_import() {
        return true;
    }

    function provide_export() {
        return true;
    }

    ///////////////////////////////////////////////////////////////////////////
    ///////////    Variables and initialization process     ///////////////////
    ///////////////////////////////////////////////////////////////////////////

    /** Stores array of uploaded files, if zip archive is uploaded. */
    private $includes;
    /** Array of images that belong to the .tex file. */
    private $images;
    /** Renderengine used by Moodle */
    private $renderengine;
    /** Array containing configuration info from file config.php */
    private static $cfg;
    /** This script may also be used in a "stand alone" version */
    private $standalone;

    /**
     * Initializes all variables.
     *
     * Should really be the constructor, but - for no obvious reason -
     * Moodle calls the constructors of all question types already when
     * displaying the "import question" dialogue.
     * In order to avoid calling everything twice, the initialization proccess
     * is moved into the preprocessing phase.
     */
    function qformat_qtex_initialize(){
        // Read configuration from file.
        // Make sure we look only in this directory.
        if( (require(dirname(__FILE__) . '/config.php')) ){
            self::$cfg = $cfg;
        }
        else{
            $this->error(get_string('configmissing', 'qformat_qtex'));
        }

        // Stand alone mode is turned on by defining a flag
        if(defined('TEXFORMAT_STANDALONE')) $this->standalone = true;
        else $this->standalone = false;

        $this->includes = NULL;
        $this->images = NULL;
        $this->renderengine = $this->tex_get_render_engine();
    }



    const FLAG_JSMATH = 0;
    const FLAG_MIMETEX = 1;
    /**
     * Sets $this->renderengine to Moodle's render engine.
     */
    function tex_get_render_engine(){
        global $CFG;

        $filters = get_list_of_plugins('filter');
        // TODO: Check whether filter is also active and not just available
        if (array_search('tex', $filters) !== FALSE){
            return self::FLAG_MIMETEX;
        }
        elseif (array_search('jsmath', $filters) !== FALSE){
        }
        else{
            notify(get_string('norenderenginefound', 'qformat_qtex'));
            return self::FLAG_MIMETEX;
        }

    }

    ///////////////////////////////////////////////////////////////////////////
    ////////    End of variables and initialization process     ///////////////
    ///////////////////////////////////////////////////////////////////////////



    ///////////////////////////////////////////////////////////////////////////
    ///////////////       Import functions                /////////////////////
    ///////////////////////////////////////////////////////////////////////////

    /**
     * Initializes all variables
     */
    function importpreprocess(){
        // Initialize the format ("constructor")
        $this->qformat_qtex_initialize();

        return true;
    }


    /**
     * Reads file from user input and returns its contents in a string.
     *
     * If input is a .zip archive (containing images to include),
     * $this->includes is set to an associative array of all the files
     * included in the .zip archive. Keys are their relative paths to the
     * .tex file.
     *
     * @param string $filename Path to the uploaded file.
     * @return string The .tex file as a string
     */
    function readdata($filename){

        if (!is_readable($filename)) {
            return false;
        }

        // Get filetype of input file
        $inputfiletype = substr(strrchr($this->realfilename,"."), 1);
        $inputfiletype = strtolower($inputfiletype);

        // If we have a zip file (with pictures)
        if($inputfiletype == 'zip'){

            // Open zip archive
            $zip = new ZipArchive();
            $zipOpen = $zip->open($filename);
            if ($zipOpen !== true){

            $res = $zip->open('test.zip');
                $this->error(get_string('cannotopenzip', 'qformat_qtex', $zipOpen));
            }

            // Create a list of the paths to the files contained in the zip
            for ($i=0; $i < $zip->numFiles; $i++) {
                $zippedfilenames[$i] = $zip->getNameIndex($i);
            }

            // Find the tex file to translate (apart from the reserved ones,
            // only one tex file should be contained in the zip file)
            $texfilecounter = 0;
            $texfilematches = $this->preg_match_batch('/(.*?\.tex\z)/i', $zippedfilenames, self::PREG_DISCARD_EMPTY_MATCHES);
            foreach ($texfilematches as $texfilematch){
                if(!preg_match('/.*?'.implode('|', self::$cfg['RESERVED_NAMES']).'.*?/i', $texfilematch[0])){
                    $texfileguesses[$texfilecounter] = $texfilematch[0];
                    ++$texfilecounter;
                }
            }
            // No .tex file found
            if($texfilecounter == 0){
                $this->error(get_string('notexfound', 'qformat_qtex'));
            }
            // Too many
            elseif($texfilecounter > 1){
                $multipletexs = implode(', ', $texfileguesses);
                $this->error(get_string('multipletex', 'qformat_qtex', $multipletexs));
            }
            // Just right.
            else{
                $texfilename = $texfileguesses[0];
                $texfile = $zip->getFromName($texfilename);
            }

            // Often, the *folder* containing all the necessary files is zipped,
            // making the filepaths in the zip file longer than as they appear
            // in the tex file. We want to correct that.
            if(preg_match('/.*?(\/.*)/', strrev($texfilename), $superfolders)){
                $superfolder = strrev($superfolders[1]);
                foreach ($zippedfilenames as $i => $filename){
                    $zippedfilenames[$i] = preg_replace('/'.preg_quote($superfolder,'/').'/', '', $filename);
                }
            }

            // Now generate an array holding all the possible includes of the tex file
            foreach ($zippedfilenames as $i => $filename){
                $includes[$filename] = $zip->getFromIndex($i);
            }
            $this->includes = $includes;
            $zip->close();
        }

        // Otherwise assume tex-input
        else if ($inputfiletype == 'tex'){
            $texfile = file_get_contents($filename);
        }
        // Else, we don't know the filetype
        else{
            $this->error(get_string('unknownfiletype', 'qformat_qtex', $inputfiletype));
        }

        return $texfile;
    }

    /**
     * Uses LaTeX distribution to parse QuestionTeX macros.
     *
     * @param string $tex The uploaded TeX file in a string
     * @return string The parsed result
     */
    function import_process_latex($tex){

        // TODO: Decide whether to use latex for parsing or not

        // Get contents of macro file and put them into the tex file
        if(empty(self::$cfg['MACRO_FILE'])){
            $macrofilename = dirname(__FILE__).'/'.self::$cfg['MACRO_FILENAME'];

            if(file_exists($macrofilename)){
                self::$cfg['MACRO_FILE'] = file_get_contents($macrofilename);

                // Remark: Use preg_replace only, if the replacement string
                // is known, since some characters have special meaning and
                // there is no standard way of escaping them.
                $inputregexp = self::create_tex_rgxp(array('input'));
                preg_match('/'.$inputregexp.'/s', $tex, $matches);
                $inputstring = $matches[0];
                $tex = str_replace($inputstring, self::$cfg['MACRO_FILE'], $tex);
            }
            else{
                error(get_string('macrosmissing', 'qformat_qtex'));
            }
        }

        // Check for availability of LaTeX distribution
        if(empty(self::$cfg['PATH_TO_LATEX'])){
            global $CFG;

            if(!empty($CFG->filter_tex_pathlatex)){
                self::$cfg['PATH_TO_LATEX'] = $CFG->filter_tex_pathlatex;
            }
            else{
                error(get_string('latexdistromissing', 'qformat_qtex'));
            }

        }

        // Create temporary directory
        $systempdir = sys_get_temp_dir();
        $tempfilepath = tempnam($systempdir, "QTEX"); // Automatically creates file
        unlink($tempfilepath);
        $tempdir = $tempfilepath;
        if(!mkdir($tempdir)) error(get_string('tempdir', 'qformat_qtex'));

        // Create temporary tex file
        $jobname = 'temp';
        $texfile = $tempdir."/$jobname.tex";
        $fh = fopen($texfile, 'w');
        fputs($fh, $tex);
        fclose($fh);

         // Assemble command
         $command = $this->clean_path(self::$cfg['PATH_TO_LATEX']);
         //$command .= ' --interaction=nonstopmode';
         $command .= ' '.$this->clean_path($texfile);

         // And run it
         chdir($tempdir);
         $this->execute($command, $output, $returncode);
         if($returncode){
            error(get_string('latexcompilation', 'qformat_qtex', implode("\n",$output)));
         }

         // Read parsed file and remove temporary files
         $parsed = file_get_contents($tempdir."/$jobname.log");
         chdir($systempdir);
         // Must delete all files before using rmdir. CHILD_FIRST ensures
         // that children always come before parents (=> rmdir ok)
         $iterator = new RecursiveDirectoryIterator($tempdir);
         foreach (new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::CHILD_FIRST) as $file){
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
         }
         unset($iterator);  // Needed for permission to delete
         if(!rmdir($tempdir)) error(get_string('tempdir', 'qformat_qtex'));

         return $parsed;
    }

    /**
     * Trims and quotes paths, if necessary.
     *
     * @param string $path Some filepath
     * @return string Formatted filepath
     */
    function clean_path($path){
        $path = trim($path);
        // In Windows, paths containing spaces must be quoted.
        // Works also in Linux, so do it in both cases.
        if(strpos($path, " ")){
            // Add enclosing quotes if not yet present
            if(!(substr($path, 0, 1) == '"' && substr($path, -1) == '"')){
                $path = '"'.$path.'"';
            }
        }
        return $path;
    }

    /**
     * Executes $command on the command line in the current working directory.
     *
     * @param string $command Command to be executed
     * @param &string $output Reference to console output, if error occurs
     * @param &int $returncode Reference to return code
     * @return int The return code, i.e. 0 for success, rest for error
     */
    function execute($command, &$output, &$returncode){
        // If we are running under Windows
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // The old problem with spaces in paths.
            // The extra cmd is needed, since PHP cannot handle commands,
            // where both program and arguments are quoted (a bug obviously)
            $command = "cmd /c \"$command\"";
        }

        exec($command, $output, $returncode);
        return $returncode;
    }

    /**
     * Converts tex file into array of questions.
     *
     * First, the tex code is prepared for display in HTML environment.
     * Then, it is decomposed into questions, calling auxiliary functions for
     * each question type and returning an array of question objects.
     *
     * @param string $texfile The uploaded .tex file as a string
     * @return array Array of question objects
     */
    function readquestions($texfile){
        if(self::$cfg['GO_VIA_LEM'] == true){
            $parsed = $this->import_process_latex($texfile);
        }
        // TODO: Adapt code from here on to process lem output

        $tex = $this->import_prepare_for_display($texfile);

        $this->import_get_images($tex);

        // Array to hold the questions
        $questions = array();
        $questioncount = 0;

        // Handle category
        if($category = $this->import_category($tex)){
            $questions[0] = $category;
            ++$questioncount;
        }

        // Now store the environment content in $texenvironments
        $environmentregexp = $this->create_tex_rgxp(array_keys(self::$cfg['ENVIRONMENTS']), self::FLAG_ENVIRONMENT_MATCH, 1, self::FLAG_GET_IDENTIFIER);
        preg_match_all('/'.$environmentregexp.'/sx', $tex, $texenvironments, PREG_SET_ORDER);

        // For each tex environment perform the following operations
        for($texenvironmentcount = 0; isset($texenvironments[$texenvironmentcount]); $texenvironmentcount++){

            $qobject = $this->readquestion($texenvironments[$texenvironmentcount]);

            // Add question to quiz and increase corresponding count
            if(isset($qobject)){
                $questions[$questioncount] = $qobject;
                ++$questioncount;
            }
            unset($qobject);
        }

        return $questions;
    }

    /**
     * Prepares the TeX-Code for display in Moodle.
     *
     * Strips text down to document-environment, removes comments,
     * does HTML-representation etc.
     *
     * @param string $tex The uploaded TeX-file in a string
     * @return string The processed TeX-file in a string
     */
    function import_prepare_for_display($tex){

        // Remove full line commentaries and line break. Missing 's' option
        // prevents dot from matching newline. 'm' option makes start and end
        // line anchors match embedded newlines.
        $tex = preg_replace('/^%.*$\r?\n/m','',$tex);
        // Remove other commantaries, leave line break
        $tex = preg_replace('/(?<!\\\\)%(.*)/','',$tex);

        // Replace user-defined LaTeX macros (TODO: still beta stadium)
        preg_match('/(.*?)\\\\begin{document}(.*?)\\\\end{document}/sx', $tex, $documents);
        $head = $documents[1];
        // We discard, what's not in the document-environment
        $tex = $documents[2];
        // Store user defined macros in 'obligatory1' and what they do in 'obligatory2'
        $udefinedregexp = $this->create_tex_rgxp(array('newcommand', 'renewcommand'), self::FLAG_MACRO_MATCH, 2);
        preg_match_all('/'.$udefinedregexp.'/s', $head, $udefinedmatches, PREG_SET_ORDER);
        foreach($udefinedmatches as $match){
            // We have to remove the backslash, since we want to pass it to create_rgxp)
            $umacro = substr($match['obligatory1'], 1, 0);
            $umacroregexp = $this->create_tex_rgxp(array($umacro), self::FLAG_MACRO_MATCH, -1);
            $tex = preg_replace('/'.$umacroregexp.'/', $urepl, $tex);
        }


        // In XML, the characters <,>,& have to be replaced by their html entities.
        // I assume that this process does not destroy any of the macros
        // related to questions.
        $tex = htmlspecialchars($tex, ENT_NOQUOTES);

        // Formulae between $$ $$, \[ \], in eqnarrays, etc. are put into paragraphs
        $pformula = '<p style=\'text-align: center\' class=\'formula\'>';
        $tex = preg_replace('/\${2}([^\$]*?)\${2}/sx',$pformula.'\$\\1\$</p>', $tex);
        $tex = preg_replace('/\\\\\[(.*?)\\\\\]/sx',$pformula.'\$\\1\$</p>', $tex);
        $peqn = '<p style=\'text-align: center\' class=\'eqn\'>';
        $tex = preg_replace('/(\\\\begin\{eqnarray\*?}.*?\\\\end\{eqnarray\*?})/sx',$peqn.'\$\\1\$</p>', $tex);
        $tex = preg_replace('/\$([^\$]*?)\$/sx','\$\$\\1\$\$', $tex);

        // Now, since formulae and plain text are are displayed by different
        // render engines, they have to be treated differently.
        // Text is stored in even array items, formulae in uneven array items.
        preg_match_all('/((?:.*?)(?:\${2}|\Z))/sx', $tex, $sectors, PREG_PATTERN_ORDER);

        // In non-formula text, we have to replace LaTeX commands by their HTML
        // representation, while formulae are treated by the respective render
        // engine
        for($i=0; isset($sectors[1][$i]); ++$i){
            if($i % 2 == 0) $sectors[1][$i] = $this->import_prepare_text($sectors[1][$i]);
            else $sectors[1][$i] = $this->import_prepare_formula($sectors[1][$i]);
        }
        $tex = implode('', $sectors[1]);

        // JsMath has almost the same syntax, just use \(...\) instead of $$...$$
        if($this->renderengine == self::FLAG_JSMATH){
            $tex = preg_replace('/\${2}([^\$]*?)\${2}/s','\\\\(\\1\\\\)', $tex);
        }

        return $tex;
    }

    /**
     * Prepares TeX-formatted text for display in Moodle (HTML)
     *
     * @param string $text Some TeX-formatted text.
     * @return string The processed text.
     */
    function import_prepare_text($text){

        $text = preg_replace('/^\r?\n(?:^\r?\n)+/m', '\\\\\\\\', $text); // Replace successional empty lines by \\ (m-option lets ^ match after newlines)
        $text = preg_replace('/\r?\n/', ' ', $text); // Then replace left over normal line breaks just by a free space
        $text = preg_replace('/\\\\\\\\/', '<br/>', $text); // Finally, implement the remaining \\

        $text = preg_replace('/~/', ' ', $text); // Replace non-breaking spaces by space
        $text = preg_replace('/--/','-', $text);
        $text = preg_replace('/\\\\,/','', $text); // Replace thin spaces when not in math mode
        $text = preg_replace('/\\\\ /','', $text); // Replace thin spaces when not in math mode
        $text = preg_replace('/\\\\emph\{(.*?)}/s','<i>\\1</i>', $text);
        $text = preg_replace('/\\\\textit\{(.*?)}/s','<i>\\1</i>', $text);
        $text = preg_replace('/\\\\textbf\{(.*?)}/s','<b>\\1</b>', $text);
        $text = preg_replace('/\\\\bf/s','', $text); // bf toggles bold font (normally somewhere enclosed by {}, but we ignore it here
        $text = preg_replace('/\\\\\((.*?)\)/s','<i>\\1</i>', $text); // \(...\) is another way of italic
        $text = preg_replace('/\\\\underline\{(.*?)}/s','<u>\\1</u>', $text);
        $text = preg_replace('/\\\\(\{|\})/s','\\1', $text); // Remove escaping from curly brackets
        $text = preg_replace('/\\\\vskip\S*/s','', $text);

        // Replace both \"o and "o by a Unicode ö
        $text = preg_replace('/(\\\\"o|"o)/','ö', $text);
        $text = preg_replace('/(\\\\"O|"O)/','Ö', $text);
        $text = preg_replace('/(\\\\"a|"a)/','ä', $text);
        $text = preg_replace('/(\\\\"A|"A)/','Ä', $text);
        $text = preg_replace('/(\\\\"u|"u)/','ü', $text);
        $text = preg_replace('/(\\\\"U|"U)/','Ü', $text);
        $text = preg_replace('/(\\\\"s|"s)/','ß', $text);

        // Handle some special symbols
        $text = preg_replace('/\\\\Big(\W{1})/','<font size=\'+1\'>\\1</font> ', $text);
        $text = preg_replace('/\\\\textbackslash/','&#92;', $text);

        return $text;
    }

    /**
     * Prepares a formula for display with MimeTeX/JsMath
     *
     * @param string $formula
     * @return string Processed formula.
     */
    function import_prepare_formula($formula){

        // Destroy certain Moodle Emoticons
        $formula = preg_replace('/\(y\)/s', '({}y)', $formula);
        $formula = preg_replace('/\(h\)/s', '({}h)', $formula);
        $formula = preg_replace('/\(n\)/s', '({}n)', $formula);
        $formula = preg_replace('/\( \)/s', '({} )', $formula);
        $formula = preg_replace('/:\(/s', ':{}(', $formula);
        $formula = preg_replace('/\^-\)/s', '^{}-)', $formula);

        // Replace "o by \"o, since the Moodle TeX-render-engine doesn't understand "o
        $formula = preg_replace('/(?<!\\\\)"/','\\\\"', $formula);

        // Handle some formatting commands
        $formula = preg_replace('/\\\\enspace/s','\\ ', $formula);

        return $formula;
    }


    /**
     * Searches TeX string for includes and imports them.
     *
     * Checks, whether all included images are provided and notifies, if not.
     * Sets $this->images to an associative array, where the keys are the exact
     * include-paths, ['incpath']['content'] their base64 encoded content and
     * ['incpath']['type'] their type (file extension).
     *
     * @param string $tex TeXfile that possibly contains includes
     *    (already processed by import_prepare_for_display()).
     */
    function import_get_images($tex){

        // First, we have to know whether images are included in the tex file. If yes, create a list.
        $imageregexp = $this->create_tex_rgxp(array('image'), self::FLAG_MACRO_MATCH);
        preg_match_all('/'.$imageregexp.'/s', $tex, $teximagematches, PREG_SET_ORDER);
        foreach ($teximagematches as $i => $teximagematch) {
            $teximages[$i] = $teximagematch['obligatory1'];
        }

        // If there are any images included in the tex file
        if(isset($teximages)){
            $includes =& $this->includes;
            // If includes have been provided
            if(!empty($includes)){
                foreach ($teximages as $teximage) {
                    // Search for each latex-image-name $teximage in the provided files.
                    // Remark: In LaTeX, images may (or rather should) be included without a file extension.
                    // That means, we must search for $teximage, followed by 'end of string' or '.'
                    $providedmatches = $this->preg_match_batch('/('.preg_quote($teximage, '/').'(?:\.|\z).*)/i', array_keys($includes), self::PREG_DISCARD_EMPTY_MATCHES);

                    // If image is found in includes,
                    if(isset($providedmatches[0][1])) {
                        $providedname = $providedmatches[0][1];

                        // Do a check on file extension
                        $providedtype = substr(strrchr($providedname,"."), 1);
                        $allowedtypes = $this->preg_match_batch('/'.preg_quote($providedtype, '/').'/i', self::$cfg['IMAGE_FORMATS'], self::PREG_DISCARD_EMPTY_MATCHES);
                        if(!isset($allowedtypes[0][0])) notify(get_string('unsupportedimagetype', 'qformat_qtex', $providedname));

                        // Add it to the image list
                        $images[$teximage]['content'] = base64_encode($includes[$providedname]);
                        $images[$teximage]['type'] = $providedtype;

                    }
                    else notify(get_string('imagemissing', 'qformat_qtex', $teximage));
                }
            }
            // If no images are provided, we complain
            else{
                notify(get_string('allimagesmissing', 'qformat_qtex'));
            }

            // Put images into $this
            $this->images = $images;
        }

    }

    /**
     * Standard function for handling import of questions.
     *
     * Delegates tasks to specialized import functions
     *
     * @param array $ematch The match of a LaTeX environment
     * @return object The question object to insert into Moodle or NULL, if
     *      type of question unknown.
     */
    function readquestion($ematch){

        // Figure out, whether we know this question type
        if(!empty($ematch['multichoice'])) $qtype = 'multichoice';
        // If we have a remark here
        elseif(!empty($ematch['description'])) $qtype = 'description';
        // Else we do not know what we have here
        else notify(get_string('unknownenvironment', 'qformat_qtex', $ematch['identifier']));

        if(isset($qtype)){
            $qobject = $this->defaultquestion();

            // Get question text plus files
            $questiontext = $this->import_tex_string($ematch['obligatory1']);
            $qobject->questiontext = $questiontext['text'];
            $qobject->questiontextfiles = $questiontext['files'];
            $qobject->questiontextformat = $questiontext['format'];

            // Get name
            if(!empty($ematch['optional'])) $name = $ematch['optional'];
            // If not set, we take the beginning of the question.
            else $name = $qobject->questiontext;
            // We have to clean the names (Moodle does not allow special characters)
            $name = preg_replace('/[^a-z_\.0-9\s]*/i', '', $name);
            $qobject->name = substr($name, 0, self::$cfg['QNAME_LENGTH']);

            // Call the right specialized function
            $importfunction = 'import_'.$qtype;
            $qobject = $this->{$importfunction}($ematch, $qobject);

            return $qobject;
        }
        else{
            return NULL;
        }
    }

    /**
     * Imports generic part of an answer
     *
     * @param array $amatch The preg_match from an answer macro
     * @param array $qname The name of the question, passed for error messages
     * @return object The answer object created from this information
     */
    function import_answer($amatch, $qname){
        $aobject = new stdclass;

        // Get credit points for answer from optional parameter
        if(!empty($amatch['optional'])){

            // Complain, if it contains bad characters
            if(preg_match('/[^\+\-\.\d]+/', $amatch['optional'])){
                $notification->question = $qname;
                $notification->fraction = $amatch['optional'];
                notify(get_string('badpercentage', 'qformat_qtex', $notification));

            }

            // Moodle stores the percentage as fractions <= 1
            $original = floatval($amatch['optional']);
            $fraction = $original / 100.0;

            // For no really good reason, Moodle only accepts a finite array
            // of grades. We built in functionality to get the next nearest
            // allowed grade.
            $grades = get_grade_options();
            $newfraction = match_grade_options($grades->gradeoptionsfull, $fraction, 'nearest');

            // Since PHP's floats are badly implemented, one can not compare
            // them on equality. We have to convert them to strings.
            if((string) $fraction != (string) $newfraction){
                $notification->original = $original;
                $notification->new = $newfraction * 100;
                $notification->question = $qname;
                notify(get_string('changedpercentage', 'qformat_qtex', $notification));
            }
            $aobject->fraction = $newfraction;
        }
        // If not specified, check whether answer is true
        elseif(!empty($amatch['true'])){
            $aobject->fraction = 1;
        }
        // Else, the answer is wrong. For single answers, we want to set the fraction to 0,
        // for multiple answers we set it to -100 (we don't like guessing)
        else{
            $aobject->fraction = self::$cfg['DEFAULT_WRONG_WEIGHT'];
        }

        // Get answertext
        $aobject->answer = array();
        $answer = $this->import_tex_string($amatch['obligatory1']);
        $aobject->answer['text'] = $answer['text'];
        $aobject->answer['files'] = $answer['files'];
        $aobject->answer['format'] = $answer['format'];


        // Get feedback (false, if no feedback)
        $aobject->feedback = array();
        $feedback = $this->import_tex_string($this->tex_rgxp_match(array('feedback'), $amatch['tail']));
        $aobject->feedback['text'] = $feedback['text'];
        $aobject->feedback['files'] = $feedback['files'];
        $aobject->feedback['format'] = $feedback['format'];

        return $aobject;
    }


    /**
     * Writes the multiple choice question from a string into a question object.
     *
     * @param array $ematch The preg_match from a 'multichoice' environment
     * @param object $qobject The current questionobject that has been
     *      preprocessed by $this->readquestion.
     * @return object The question object created from this information.
     */
    function import_multichoice($ematch, $qobject){
        $qobject->qtype = MULTICHOICE;

        $econtent = $ematch['content'];

        // Multianswer?
        if($this->tex_rgxp_match(array('multianswer'), $econtent, self::FLAG_SINGLE_MATCH, -1)) $qobject->single = false;
        else $qobject->single = true;

        // Forbid shuffling of answers for this particular question?
        if($shuffle = $this->tex_rgxp_match(array('shuffleanswers'), $econtent, self::FLAG_SINGLE_MATCH, 1)){
            if($shuffle == "false") $qobject->shuffleanswers = false;
            else $qobject->shuffleanswers = true;
        }
        else $qobject->shuffleanswers = true;

        // Get explanation
        $generalfeedback = $this->import_tex_string($this->tex_rgxp_match(array('explanation'), $econtent));
        $qobject->generalfeedback = $generalfeedback['text'];
        $qobject->generalfeedbackfiles = $generalfeedback['files'];
        $qobject->generalfeedbackformat = $generalfeedback['format'];

        // Make a list of answer-macros
        $answerregexp = $this->create_tex_rgxp(array('true', 'false'), self::FLAG_MACRO_MATCH, 1, self::FLAG_GET_IDENTIFIER);
        // And a list of macros after that we may stop searching for a feedback (\z means "end of string").
        // Since we are appending here, we must explicitly prohibit parameter matching the second time
        $stopmacros = array('true', 'false', 'explanation', 'question', 'description');
        $answerregexp .= '(?<tail>.*?)(?='.$this->create_tex_rgxp($stopmacros, self::FLAG_MACRO_MATCH, -1, self::FLAG_NO_IDENTIFIER).'|\z)';
        preg_match_all('/'.$answerregexp.'/sx', $econtent, $texanswers, PREG_SET_ORDER);

        // For each answer perform the following operations
        for($answercount = 0; isset($texanswers[$answercount]); $answercount++){
            $amatch = $texanswers[$answercount];

            $aobject = $this->import_answer($amatch, $qobject->name);

            $qobject->answer[$answercount] = $aobject->answer;
            $qobject->feedback[$answercount] = $aobject->feedback;
            $qobject->fraction[$answercount] = $aobject->fraction;

            unset($aobject);
        }

        return $qobject;
    }

    /**
     * Writes a remark from a string into a question object.
     *
     * @param array $ematch The preg_match from a 'remark' environment
     * @return object The question object created from this information.
     */
    function import_description($ematch, $qobject){
        $qobject->qtype = DESCRIPTION;

        return $qobject;
    }

    /**
     * Creates dummy question to specify the import category.
     * It tries to take the quiz title.
     *
     * @param string $tex The pre-processed TeX file
     * @return object $category The category question
     */
    function import_category($tex) {
        $category = new stdClass;
        $category->qtype = 'category';

        // Try to get category from quiz title
        $texcategory = $this->tex_rgxp_match(array('title'), $tex);
        if(empty($texcategory)){
            $texcategory = get_string('importcategory', 'qformat_qtex');
        }
        // Moodle does not like all characters as categories
        $category->category = preg_replace('/[^a-z_\.0-9\s]*/i', '', $texcategory);


        return $category;
    }

    const FLAG_ENVIRONMENT_MATCH = 1;
    const FLAG_MACRO_MATCH = 0;
    const FLAG_GET_IDENTIFIER = true;
    const FLAG_NO_IDENTIFIER = false;
    /**
     * Creates an appropriate regular expression to match LaTeX commands
     * via preg_match.
     *
     *  @property All input is preg_quoted, no need to worry about collisions
     *      with regexp engine.
     *  @property All created regexps should be run in /s mode and must not be
     *      *appended* to other capturing regexps.
     *  @property The identifier is only matched, if directly at the beginning
     *      of the subject. If you want to find next occurence, prepend .*?
     *
     *  @param array $identifiers An array of all identifiers (macros) that
     *      should be matched. The function will first try to find the macros
     *      belonging to each identifier in the configuration. If an identifier
     *      is not defined in the configuration, it is treated as a macro.
     *      $identifiers may also be left empty, if only parameters should be
     *      matched.
     *  @param int $mode The function can operate in two modes:
     *    - FLAG_MACRO_MATCH (default): Match any of the macros and store
     *     parameters into backreferences as specified by $paramcount.
     *    - FLAG_ENVIRONMENT_MATCH: Match any of the environments. Stores
     *     content of environment into $matches['content'].
     *  @param int $paramcount Possible values:
     *    - n >= 0 (default n = 1): The first n obligatory parameters are
     *      stored into $matches['obligatoryn'] (n being the number of the
     *      parameter). The optional parameter is tried to match into
     *      $matches['optional']
     *    - -1 : Suppress match of optional parameter
     *  @param bool $getidentifier If true, the matched command itself is
     *      captured into $matches['$identifier'].
     *      Here, $identifier is either the corresponding identifier from the
     *      config or the command itself (if it is not taken from the config).

     *  @return string The regexp string
     */
    function create_tex_rgxp(
    $identifiers = NULL,
    $mode = self::FLAG_MACRO_MATCH,
    $paramcount = 1,
    $getidentifier = self::FLAG_NO_IDENTIFIER){

        // In LaTeX, a macro is terminated by a whitespace, by [ or by {
        $macroterminator = '(?=\s|\[|\{)';
        $commands = array_merge(self::$cfg['MACROS'], self::$cfg['ENVIRONMENTS']);

        // Preg_quote input, if we have any identifiers to match
        if(isset($identifiers)){
            foreach($identifiers as $identifier){
                // If we read from the config, store preq_quoted stuff in $tempcommands
                if(isset($commands[$identifier])){
                    $i = 0;
                    foreach($commands[$identifier] as $command){
                        $tempcommands[$identifier][$i] = preg_quote($command, '/');
                        ++$i;
                    }
                }
                // Else store single preq_quoted command in $tempcommands[0]
                else{
                    $tempcommands[$identifier][0] = preg_quote($identifier, '/');
                }
            }
        }

        // Now start creating the regular expression.

        // We create the part for the commands
        foreach($tempcommands as $id => $idcommands){
            if($getidentifier) $idregexp[$id] = '(?<'.$id.'>';
            $idregexp[$id] .= implode('|', $idcommands);
            if($getidentifier) $idregexp[$id] .= ')';
        }
        $commandregexp = implode('|', $idregexp);
        $regexp .= '\\\\(?:'.$commandregexp.')'.$macroterminator;

        // Now we start matching parameters

        // For $paramcount == -1, no parameters are matched, not even optional
        if($paramcount != -1){
            // Try to capture optional parameter
            // Could also be done in a balanced way, but I think this would really be "mit Kanonen auf Spatzen schiessen".
            $regexp .= '\s*(?:\[(?<optional>[^]]*)])?';

            // Get defined number of obligatory parameters
            for($currparam = 1; $currparam <= $paramcount; ++$currparam){
                // Regexp to match the content of balanced brackets
                // Remark: The closing bracket at the end doesn't have to be checked for escaping. If it matches after the first path, it can not be
                // escaped since this path checks for it and if it matches after the second path, the preceding symbol must be '}'.
                $regexp .= '\s*(?<rec'.$currparam.'> (?<![^\\\\]\\\\)\{ (?<obligatory'.$currparam.'> (?> (?> [^{}] | (?<=[^\\\\]\\\\)[{}] )+ | (?&rec'.$currparam.'))*  )})';
            }
            // Remove whitespaces (I put them in only for better readability)
            $regexp = str_replace(" ", "", $regexp);
        }

        // Finalize regexp

        // Environment mode: Match until next environment tag or end of file
        if($mode == self::FLAG_ENVIRONMENT_MATCH){
            $envstop = self::create_tex_rgxp(array_keys(self::$cfg['ENVIRONMENTS']), self::FLAG_MACRO_MATCH, -1, self::FLAG_NO_IDENTIFIER);
            $regexp .= "(?<content>.*?)(?=$envstop|\z)";
        }

        return $regexp;
    }


    const FLAG_SINGLE_MATCH = 2;
    const FLAG_MATCHES_ARRAY = 1;
    /**
     * Auxiliary function for create_tex_rgxp().
     *
     * Finds the next occurence of identifiers and directly returns matches or
     * false, if match failed.
     * The return value can either be an array of matched parameters, a single
     * matched parameter as a string or 'true', if the match is successful.
     *
     * @uses $this->create_tex_rgxp()
     *
     * @param array $identifiers An array of all TeX identifiers are of interest.
     * @param string $subject The subject, supposedly containing the macro.
     * @param int $return Should we return:
     *   - FLAG_SINGLE_MATCH (default): The parameter defined by $param (by
     *     default the first obligatory one) as a string.
     *   - FLAG_MATCHES_ARRAY: The full array of matches. $param specifies the
     *     number of obligatory parameters to match.
     * @param mixed $param
     *   - 1,..: Number of obligatory parameters to match (FLAG_MATCHES_ARRAY)
     *     or the number of the obligatory parameter to return.
     *   - 0: Will return the optional parameter.
     *   - -1: Also suppresses match of optional parameter. In
     *   FLAG_SINGLE_MATCH mode, only true/false will be returned in this case.
     * @param bool $getidentifier If true, the matched command itself is
     *      captured into $matches['$identifier'].
     * @param int $mode Are we matching:
     *   - FLAG_MACRO_MATCH (default): A simple TeX macro
     *   - FLAG_ENVIRONMENT_MATCH: A TeX environment
     * @return The preg_match containing matched data.
     */
    function tex_rgxp_match(
    $identifiers = NULL,
    $subject = '',
    $return = self::FLAG_SINGLE_MATCH,
    $param = 1,
    $getidentifier = false,
    $mode = self::FLAG_MACRO_MATCH){

        $rgxp = $this->create_tex_rgxp($identifiers, $mode, $param, $getidentifier);

        // If command is found somewhere...
        if(preg_match('/.*?'.$rgxp.'/sx', $subject, $matches)){

            // If we want to return array of matches
            if($return == self::FLAG_MATCHES_ARRAY){
                return $matches;
            }

            // If we want to return a string only
            elseif($return == self::FLAG_SINGLE_MATCH){
                // Return nth obligatory parameter
                if($param > 0) return $matches['obligatory'.$param];
                // Or optional parameter (may not be set)
                elseif($param == 0){
                    if(isset($matches['optional'])) return $matches['optional'];
                    else return false;
                }
                // Or true
                elseif($param == -1) return true;
            }
        }

        // If command is not found, return false
        else{
            return false;
        }
    }

    const PREG_DISCARD_EMPTY_MATCHES = 1;
    /**
     * Runs preg_match on an array of strings and returns a result set.
     *
     * @author wjaspers4[at]gmail[dot]com, Leopold Talirz
     * @param string $expr The expression to match against
     * @param array $batch The array of strings to test.
     * @param int $flag Determines, whether to discard empty matches
     * @return array The array of matches
     */
    function preg_match_batch($expr, $batch=array() , $flag = 0)
    {
        $matches = array();
        $i=0;

        // If empty matches should be discarded
        if($flag == self::PREG_DISCARD_EMPTY_MATCHES){
            foreach( $batch as $str ){
                if(preg_match($expr, $str, $found)){
                    $matches[$i] = $found;
                    ++$i;
                }
            }
        }

        // If empty matches should not be discarded
        else{
            foreach( $batch as $str ){
                preg_match($expr, $str, $found);
                $matches[$i] = $found;
                ++$i;
            }
        }

        return $matches;
    }


    const FLAG_IMAGE = true;
    const FLAG_NO_IMAGE = false;
     /**
     * Prepares TeX string for insertion into Moodle database.
     *
     * Searches TeX string $string for included images,
     * returns string with links to embedded images plus array of image files.
     *
     * @param string $string Some TeX string
     * @param bool $mayimage Should string be checked for images?
     * 		(on by default).
     * @return array
     * 		$array['text'] holds processed $string
     * 		$array['format'] holds integer indicating the text format
     * 		$array['files'] holds needed files (images)
     */
    function import_tex_string($string, $mayimage = self::FLAG_IMAGE){

        // Insert image to string, if applicable
        // (necessary escaping must be done in here)
        $files = array();
        if($mayimage == self::FLAG_IMAGE){

            // Create regexp to find images
            $imageregexp = $this->create_tex_rgxp(array('image'));

            // Search string
            preg_match_all('/'.$imageregexp.'/s', $string, $imagenames, PREG_SET_ORDER);

            // For each included image perform the following operations
            for($imagecount = 0; isset($imagenames[$imagecount]); $imagecount++){
                $imagename = $imagenames[$imagecount]['obligatory1'];

                // If we found an image, it should be in the list
                // (has been checked previously by $this->import_get_images)
                if(isset($this->images[$imagename])){
                    // Get image file
                    $image = $this->images[$imagename];

                    $file = new stdClass();
                    $file->content = $image['content'];
                    $file->encoding = 'base64';
                    $file->name = $imagename;

                    $files[$imagecount] = $file;

                    // Embed image into string
                    $thatimageregexp = $this->create_tex_rgxp(array('image'), self::FLAG_MACRO_MATCH, 0, self::FLAG_NO_IDENTIFIER);
                    $thatimageregexp .= '\{'.preg_quote($imagename, '/').'}';
                    $repstring = "\n".'<img src="@@PLUGINFILE@@/'. $imagename .'" alt="'. $imagename .'" align="center">';

                    $string = preg_replace('/'.$thatimageregexp.'/s', $repstring, $string);
                }
                else notify(get_string('imagemissing', 'qformat_qtex', $imagename));
            }
        }

        $full = array();
        $full['text'] = $string;
        $full['files'] = $files;
        $full['format'] = FORMAT_MOODLE;

        return $full;
    }

    ///////////////////////////////////////////////////////////////////////////
    ///////////////           End of Import functions            //////////////
    ///////////////////////////////////////////////////////////////////////////

    ///////////////////////////////////////////////////////////////////////////
    ///////////////       Export functions                /////////////////////
    ///////////////////////////////////////////////////////////////////////////

    /**
     * Initializes all variables.
     */
    function exportpreprocess(){
        // Initialize the format ("constructor")
        $this->qformat_qtex_initialize();

        return true;
    }


    /**
     * Returns default file extension.
     *
     * If images are involved, we should give back a zip archive.
     * For some stupid reason, the Moodle 2 export function creates a new
     * qformat instance to call this function, so this function does not
     * work anymore.
     *
     * @return string The file extension.
     */
    function export_file_extension() {
        if(!empty($this->images)){
            return '.zip';
        }
        else{
            return '.tex';
        }
    }

    /**
     * Translate internal Moodle code number into human readable format
     *
     * @param mixed $type_id Internal code
     * @return string Identifier of corresponding LaTeX environment
     */
    function get_identifier($qtypeid) {
        switch($qtypeid) {
            case MULTICHOICE:
                $identifier = 'multichoice';
                break;
            case DESCRIPTION:
                $identifier = 'description';
                break;
                // There is no constant for categories, they come in plain text
            case 'category':
                $identifier = 'category';
                break;
            default:
                $identifier = false;
        }
        return $identifier;
    }

    /**
     * Creates the tex code for a single question object
     *
     * @see plugin/qformat_default#writequestion($question)
     * @param object $question A question object fresh from the moodle database
     * @param string The tex code corresponding to this question
     */
    function writequestion($question){
        $identifier = $this->get_identifier($question->qtype);

        // If our get_identifier function knows the question type
        if($identifier){

            // Call the right specialized function to get the content of the
            // tex environment.
            $exportfunction = 'export_'.$identifier;
            $content = $this->{$exportfunction}($question);

            // Keep track of non-embedded images (DEPRECATED)
            if(!empty($question->image)){
                // Get file name
                preg_match('/(.*?)\//', strrev($question->image), $imagenamesrev);

                $image['includename'] = get_string('imagefolder', 'qformat_qtex').strrev($imagenamesrev[1]);
                $image['filepath'] = $question->image;
                $this->images[$image['includename']] = $image;

                // Add image in front of content
                $imagetag = $this->create_macro('image', array($image['includename']));
                $content = $imagetag.$content;

                unset($image);
            }

            // Handle questiontext images
            $this->writeimages($question->questiontextfiles);

        }
        // Else we don't know the type
        else{
            notify(get_string('unknownexportformat', 'qformat_qtex', $question->qtype));
        }

        return $content;
    }

    /**
     * Imports image into $this->images
     *
     * @param array $files of $file objects with functions
     * 		get_filename(), get_content()
     */
    function writeimages($files = NULL, $encoding = 'base64'){
        if(isset($files)){
            foreach ($files as $file) {
                if ($file->is_directory()) {
                    continue;
                }

                $includename = get_string('imagefolder', 'qformat_qtex').$file->get_filename();
                $this->images[$includename] = $file->get_content();
            }
        }

    }

    /**
     * Returns the content of a TeX environment for a multiple choice question
     *
     * @param object $question A question object from the Moodle data base
     * @return string The corresponding TeX string
     */
    function export_multichoice($question){

        $econtent = $this->create_macro($this->get_identifier($question->qtype), array($question->questiontext), $question->name);

        // Shuffle answers?
        if($question->shuffleanswers == '0'){
            $econtent .= $this->create_macro('shuffleanswers', array('false'));
        }

        // Multiple correct answers?
        if($question->single == false || $question->single == 'false'){
            $econtent .= $this->create_macro('multianswer');
            // We need to know this for handling the answer fractions
            $single = false;
        }
        else{
            $single = true;
        }

        // Handle answers. For export, the Moodle geniuses invented sth new:
        // $question->options->answers, where each answer object has
        // $answer->answer, $answer->fraction, $answer->feedback
        foreach($question->options->answers as $aobject){

            // Handle percentage
            // We don't check, if fractions add up to 100. Could be done
            $percentage = floatval($aobject->fraction) * 100;
            if($percentage > 0){
                $identifier = 'true';
            }
            else{
                $identifier = 'false';
            }

            // If we have a single-answer question, we dont specify a percentage
            if($single){
                $econtent .= $this->create_macro($identifier, array($aobject->answer));
            }
            // If we have a multi-answer question, we do...
            else{
                $econtent .= $this->create_macro($identifier, array($aobject->answer), $percentage);
            }
            // Handle answer images
            $this->writeimages($aobject->answerfiles);

            // Get feedback
            if(!empty($aobject->feedback)){
                $econtent .= $this->create_macro('feedback', array($aobject->feedback));
            }
        }

        // At the end of the question, get general feedback
        if(!empty($question->generalfeedback)){
            $econtent .= $this->create_macro('explanation', array($question->generalfeedback));

            // Handle general feedback images
            $this->writeimages($aobject->feedbackfiles);
        }

        return $econtent;
    }

    /**
     * Returns the content of a TeX environment for a description
     *
     * @param object $question A question object from the Moodle data base
     * @return string The corresponding TeX string
     */
    function export_description($question){

        $econtent = $this->create_macro($this->get_identifier($question->qtype), array($question->questiontext), $question->name);

        return $econtent;
    }

    /**
     * Changes the category (represented as a header in LaTeX
     *
     * @param object question A category
     * @return string tex The category to put into the LaTeX code
     */
    function export_category($question){
        // Used for category switching
        $tex = self::$cfg['NL'];

        // We remove variables like $course$/thecategory
        $question->category = preg_replace('/\$(?:.*?)\$\/?/s', '', $question->category);

        $tex .= $this->create_macro($this->get_identifier($question->qtype), array($question->category), $question->name);
        $tex .= self::$cfg['NL'];

        return $tex;
    }

    /**
     * Creates a TeX macro.
     *
     * Parameters are the identifier, an array of obligatory parameters and
     * an optional parameter.
     *
     * @param string identifier Identifier being looked up in config
     * @param array obligatories Array of obligatory parameters
     * @param string optional Optional parameter
     * @return string TeX code ready to put into TeX file
     */
    function create_macro($identifier, $obligatories = '', $optional = ''){

        // Get macro name
        $commands = array_merge(self::$cfg['MACROS'], self::$cfg['ENVIRONMENTS']);
        if(isset($commands[$identifier])){
            $macroname = $commands[$identifier][0];
        }
        else{
            $macroname = $identifier;
        }

        $texcode = '\\'.$macroname;

        if(!empty($optional)){
            $texcode .= '['.$optional.']';
        }

        if(!empty($obligatories)){
            foreach($obligatories as $obligatory){
                // Handle images etc.
                //$obligatory = $this->export_tex_string($obligatory);

                $texcode .= '{'.$obligatory.'}';
            }
        }

        $texcode .= self::$cfg['NL'];

        return $texcode;
    }

    /**
     * Creates a TeX environment. (DEPRECATED, we don't use environments)
     *
     * Parameters are an identifier, the environment content, an
     * array of obligatory parameters and an optional parameter.
     *
     * @param string identifier
     * @param string environmentcontent
     * @param array obligatories
     * @param string optional
     * @return string TeX code ready to put into TeX file
     */
    function create_environment($identifier, $environmentcontent = '', $obligatories = '', $optional = ''){
        // Get environment name
        if(isset(self::$cfg['ENVIRONMENTS'][$identifier])){
            $environmentname = self::$cfg['ENVIRONMENTS'][$identifier][0];
        }
        else{
            $environmentname = $identifier;
        }
        $texcode = '\\begin{'.$environmentname.'}';

        if(!empty($optional)){
            $texcode .= '['.$optional.']';
        }

        if(!empty($obligatories)){
            foreach($obligatories as $obligatory){
                $texcode .= '{'.$obligatory.'}';
            }
        }

        $texcode .= self::$cfg['NL'];

        $texcode .= $environmentcontent;

        $texcode .= '\\end{'.$environmentname.'}';

        $texcode .= self::$cfg['NL'];

        return $texcode;
    }


    /**
     * Prepares file for download.
     *
     * If the exported questions include images, a zip file containing these
     * images will be created.
     *
     * @param string $content TeX code containing all the questions for export
     * @return string The content of the file to be downloaded
     *      (either TeX code or a zip file if images are included).
     */
    function presave_process($content){
        // Handle images
        $content = $this->export_extract_images($content);

        // The TeX code has to be cleaned from XML remains and be put into a
        // document environment.
        $texfile = $this->export_prepare_for_display($content);

        // If images have been included, we will return a zip file
        if(!empty($this->images)){

            // Create a zip file for download

            // For some reason, in Moodle 2 the notification below would
            // end up inside the file.
            //notify(get_string('questionsincludeimages', 'qformat_qtex'));

            $zip_tempname = tempnam('', 'ZIP').'.zip';
            $zip = new ZipArchive();
            if (!$zip->open($zip_tempname, ZipArchive::CREATE)){
                $this->error(get_string('cannotopenzip', 'qformat_qtex'));
            }
            // Add tex file
            $zip->addFromString(self::$cfg['QUIZ_FILENAME'], $texfile);

            // Add image files
            // Get the proper prefix to file paths
            global $CFG;
            $pathprefix = $CFG->dataroot.'/'.$this->course->id.'/';

            // Iterate through $this->images.
            foreach($this->images as $includename => $image){

                $zip->addFromString($includename, $image);
            }

            $zip->close();
            return file_get_contents($zip_tempname);
        }
        // If no images are included, we return a texfile
        else{
            return $texfile;
        }
    }

    /**
     * Handles img tags.
     *
     * Embedded images are loaded into $this->images and filepaths are added to
     * $this->imagepaths. The array fields (images) are addressed by their
     * include name.
     *
     * @param string $content The tex code produced from concatenation of
     *      $this->writequestion
     * @return string A processed tex code, where embedded images have been
     *      replaced by \includegraphics statements
     */
    function export_extract_images($content){

        // Find img tags
        preg_match_all('/<img(.*?)>/is', $content, $imgs, PREG_SET_ORDER);
        $extractedcount = 0;

        for($imgcount = 0; isset($imgs[$imgcount]); ++$imgcount){
            $img = $imgs[$imgcount];

            // Extract source of image
            if(preg_match('/.*?src=(?:\'|")([^>]*?)(?:\'|")/is', $img[1], $sources)){
                $source = trim($sources[1]);

                // An img tag may hold an embedded image.
                // Those will be loaded into $this->images
                if(preg_match('/data:image\/(?<type>.*?);(?<encoding>.*?),(?<data>.*)/is', $source, $embeddings)){
                    $embeddings['data'] = trim($embeddings['data']);
                    $embeddings['type'] = trim($embeddings['type']);

                    // We only know, how to handle base64 encoded data
                    if(preg_match('/base64/is', $embeddings['encoding'])){
                        $image['includename'] = get_string('imagefolder', 'qformat_qtex').($extractedcount+1).'.'.$embeddings['type'];

                        // Insert image into array
                        $this->images[$image['includename']] = base64_decode($embeddings['data']);
                    }
                    else{
                        notify(get_string('embederror', 'qformat_qtex'));
                    }
                }

                // Or it may hold a path to an image (which should be imported
                // elsewhere).
                else{
                    $image['includename'] = get_string('imagefolder', 'qformat_qtex').basename($source);
                    $image['filepath'] = $source;
                }

                // Replace img tag by include statement
                if(isset($image)){
                    $extractedcount++;

                    $pattern = preg_quote($img[0], '/');
                    $replacement = $this->create_macro('image', array($image['includename']));
                    $content = preg_replace('/'.$pattern.'/is',  $replacement, $content);

                    unset($image);
                }
            }
        }

        return $content;
    }

    /**
     * Cleans TeX from XML remains, embeds it into a document environment etc.
     *
     * @param string $texcode TeX code containing all the questions for export
     * @return string A real LaTeX document containing those questions.
     */
    function export_prepare_for_display($texcode){

        // Clean from XML remains (represent special entities in LaTeX, etc)
        $texcode = $this->export_prepare_xml($texcode);

        $texfile = get_string('preamble', 'qformat_qtex').self::$cfg['NL'];
        $texfile .= '\documentclass[a4paper,oneside]{article}'.self::$cfg['NL'];
        // We must include the macro file
        $texfile .= '\input{'.self::$cfg['MACRO_FILENAME'].'}'.self::$cfg['NL'];
        $texfile .= '\showsolution'.self::$cfg['NL'];
        $texfile .= '\showfeedback'.self::$cfg['NL'];
        $texfile .= self::$cfg['NL'];
        $texfile .= $this->create_environment('document', $texcode);

        // We assume, the LaTeX editor doesn't understand UTF8
        $texfile = utf8_decode($texfile);

        return $texfile;

    }

    /**
     * Handles the visual representation of individual XML entities in LaTeX
     *
     * @param string $dirty String containing XML entities
     * @return string Cleaned XML code
     */
    function export_prepare_xml($dirty){

        // No need for XML-entities anymore
        // Remark: Due to inconsistent replacing in earlier questions (before my time), sometimes instead of &lt; appears &amp;lt; . We have to fix that first.
        $dirty = preg_replace('/\&amp;/i', '&', $dirty);
        // Replace certain special characters without representation in ASCII charset by their LaTeX-representation
        $dirty = preg_replace('/\&#8730;/i', '$\sqrt{}$', $dirty);
        $dirty = preg_replace('/\&gamma;/i', '$\gamma$', $dirty);
        $dirty = preg_replace('/\&#92;/i', '\\textbackslash ', $dirty); // &#92; stands for a text backslash
        // Replace other XML-entities
        $dirty = html_entity_decode($dirty, ENT_QUOTES);


        $dirty = preg_replace('/<br[\s\/]*?>/i', '\\\\\\\\', $dirty);   // Implement <br> and <br/>

        // Handle text formatting
        $dirty = preg_replace('/<i>(.*?)<\/i>/six', '\\\\emph{\\1}', $dirty);
        $dirty = preg_replace('/<span\s*?style=(?:\"|\')\s*?font-style\s*?:\s*?italic\s*?;(?:\"|\')>(.*?)<\/span>/is', '\\\\emph{\\1}', $dirty);
        $dirty = preg_replace('/<b>(.*?)<\/b>/six', '\\\\textbf{\\1}', $dirty);
        $dirty = preg_replace('/<span\s*?style=(?:\"|\')\s*?font-weight\s*?:\s*?bold\s*?;(?:\"|\')>(.*?)<\/span>/is', '\\\\textbf{\\1}', $dirty);

        // Handle hyperlinks (simply replace them by their destination, needs to be corrected by hand most probably)
        $dirty = preg_replace('/<a[^>]*?href=(?:\"|\')(.*?)(?:\"|\')[^>]*?>.*?<\/a>/is', '\\\\emph{\\1}', $dirty);

        // Handle Umlaute. Here we may simply use str_replace
        $dirty = str_replace('ö', '\\"o', $dirty);
        $dirty = str_replace('Ö', '\\"O', $dirty);
        $dirty = str_replace('ä', '\\"a', $dirty);
        $dirty = str_replace('Ä', '\\"a', $dirty);
        $dirty = str_replace('ü', '\\"u', $dirty);
        $dirty = str_replace('Ü', '\\"U', $dirty);
        $dirty = str_replace('ß', '\\"s', $dirty);


        // For JsMath replace the brackets by $$
        if($this->renderengine == self::FLAG_JSMATH){
            $dirty = preg_replace('/\\\\\((.*?)\\\\\)/', '\$\$\\1\$\$', $dirty);
        }

        // First we put everything in single $
        $dirty = preg_replace('/\${2}/', '$', $dirty);

        // Then we add a $ for special paragraphs
        $dirty = preg_replace('/<p[^>]*?class=\'formula\'>(.*?)<\/p>/', '\$\\1\$', $dirty);
        $dirty = preg_replace('/<p.*?>(.*?)<\/p>/', '\\1', $dirty);

        return $dirty;
    }

    /**
     * Does the export processing.
     *
     * Normally, the default function is not meant to be overwritten, however I
     * cannot pass zip files otherwise.
     * Changes are highlighted by // CHANGE:
     *
     * @uses the main code of the original exportprocess() function from file
     *       'format_default.php'
     * @return boolean success
     */
    function exportprocess_standalone(){
        global $CFG;

        // create a directory for the exports (if not already existing)
        if (! $export_dir = make_upload_directory($this->question_get_export_dir())) {
            error( get_string('cannotcreatepath','quiz',$export_dir) );
        }
        $path = $CFG->dataroot.'/'.$this->question_get_export_dir();

        // get the questions (from database) in this category
        // only get q's with no parents (no cloze subquestions specifically)
        if ($this->category){
            $questions = get_questions_category( $this->category, true );
        } else {
            $questions = $this->questions;
        }

        // CHANGE: Do not notify in Moodle emulator
        if(!($this->standalone)) notify( get_string('exportingquestions','quiz'));
        $count = 0;

        // results are first written into string (and then to a file)
        // so create/initialize the string here
        $expout = "";

        // track which category questions are in
        // if it changes we will record the category change in the output
        // file if selected. 0 means that it will get printed before the 1st question
        $trackcategory = 0;

        // iterate through questions
        foreach($questions as $question) {

            // do not export hidden questions
            if (!empty($question->hidden)) {
                continue;
            }

            // do not export random questions
            if ($question->qtype==RANDOM) {
                continue;
            }

            // check if we need to record category change
            if ($this->cattofile) {
                if ($question->category != $trackcategory) {
                    $trackcategory = $question->category;
                    $categoryname = $this->get_category_path($trackcategory, '/', $this->contexttofile);

                    // create 'dummy' question for category export
                    $dummyquestion = new object;
                    $dummyquestion->qtype = 'category';
                    $dummyquestion->category = $categoryname;
                    $dummyquestion->name = "switch category to $categoryname";
                    $dummyquestion->id = 0;
                    $dummyquestion->questiontextformat = '';
                    $expout .= $this->writequestion( $dummyquestion ) . "\n";
                }
            }

            // export the question displaying message
            $count++;
            // CHANGE: No echo in stand alone
            if(!$this->standalone) echo "<hr /><p><b>$count</b>. ".$this->format_question_text($question)."</p>";
            if (question_has_capability_on($question, 'view', $question->category)){
                $expout .= $this->writequestion( $question ) . "\n";
            }
        }

        // continue path for following error checks
        $course = $this->course;
        $continuepath = "$CFG->wwwroot/question/export.php?courseid=$course->id";

        // did we actually process anything
        if ($count==0) {
            print_error( 'noquestions','quiz',$continuepath );
        }

        // final pre-process on exported data
        $expout = $this->presave_process( $expout );

        // write file
        $filepath = $path."/".$this->filename . $this->export_file_extension();

        // CHANGE: In standalone mode, we simply return $expout
        if($this->standalone) return $expout;
        else{
            if (!$fh=fopen($filepath,"w")) {
                print_error( 'cannotopen','quiz',$continuepath,$filepath );
            }
            if (!fwrite($fh, $expout, strlen($expout) )) {
                print_error( 'cannotwrite','quiz',$continuepath,$filepath );
            }
            fclose($fh);
        }

        return true;
    }

}

?>