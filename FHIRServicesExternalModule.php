<?php namespace Vanderbilt\FHIRServicesExternalModule;

require_once __DIR__ . '/vendor/autoload.php';

use DateTime;
use Exception;
use MetaData;
use REDCap;

use DCarbone\PHPFHIRGenerated\R4\FHIRResource;
use DCarbone\PHPFHIRGenerated\R4\PHPFHIRResponseParser;
use DCarbone\PHPFHIRGenerated\R4\FHIRElement\FHIRString;
use DCarbone\PHPFHIRGenerated\R4\FHIRResource\FHIRBundle;
use DCarbone\PHPFHIRGenerated\R4\FHIRElement\FHIRHumanName;
use DCarbone\PHPFHIRGenerated\R4\FHIRElement\FHIRReference;
use DCarbone\PHPFHIRGenerated\R4\FHIRElement\FHIRContactPoint;
use DCarbone\PHPFHIRGenerated\R4\FHIRElement\FHIRCodeableConcept;
use DCarbone\PHPFHIRGenerated\R4\FHIRElement\FHIRContactPointSystem;
use DCarbone\PHPFHIRGenerated\R4\FHIRElement\FHIRResearchStudyStatus;
use DCarbone\PHPFHIRGenerated\R4\FHIRResource\FHIRDomainResource\FHIRComposition;
use DCarbone\PHPFHIRGenerated\R4\FHIRResource\FHIRDomainResource\FHIROrganization;
use DCarbone\PHPFHIRGenerated\R4\FHIRResource\FHIRDomainResource\FHIRPractitioner;
use DCarbone\PHPFHIRGenerated\R4\FHIRResource\FHIRDomainResource\FHIRResearchStudy;
use DCarbone\PHPFHIRGenerated\R4\FHIRResource\FHIRDomainResource\FHIRQuestionnaireResponse;
use DCarbone\PHPFHIRGenerated\R4\FHIRElement\FHIRBackboneElement\FHIRBundle\FHIRBundleEntry;
use DCarbone\PHPFHIRGenerated\R4\FHIRElement\FHIRBackboneElement\FHIROrganization\FHIROrganizationContact;

const QUESTIONNAIRE_RECEIVED = 'Questionnaire Received';

class FHIRServicesExternalModule extends \ExternalModules\AbstractExternalModule{
    function redcap_data_entry_form($project_id, $record){
        if($this->getProjectSetting('project-type') === 'composition'){
            $projectId = $this->getProjectId();
            $urlPrefix = $this->getUrl('service.php', true);
            $urlPrefix = str_replace("&pid=$projectId", '', $urlPrefix);    
            ?>
            <script>
                (function(){
                    var pdfButton = $('#pdfExportDropdownTrigger')
                    var bundleButton = $('<a href="<?="$urlPrefix&fhir-url=/Composition/$projectId-$record/\$document"?>">Create FHIR Bundle</a>')
                    bundleButton.attr('class', pdfButton.attr('class'))
                    bundleButton.css({
                        'margin-left': '3px',
                        'min-height': '26px',
                        'margin-bottom': '15px',
                        'vertical-align': 'top'
                    })
    
                    pdfButton.after(bundleButton)
                })()
            </script>
            <?php
        }
    }

    function redcap_every_page_top(){
        if(strpos($_SERVER['REQUEST_URI'], APP_PATH_WEBROOT . 'Design/data_dictionary_upload.php') === 0){
            //$this->onDataDictionaryUploadPage();
        }
    }

    function onDataDictionaryUploadPage(){
        ?>
        <div id="fhir-upload-container" class="round" style="background-color:#EFF6E8;max-width:700px;margin:20px 0;padding:15px 25px;border:1px solid #A5CC7A;">
        </div>
        <script>
            $(function(){
                var csvSection = $('#uploadmain').parent().parent()
                var fhirSection = csvSection.clone()
                csvSection.after(fhirSection)
            })
        </script>
        <?php
    }

    function redcap_module_link_check_display($project_id, $link){
        if(
            strpos($link['url'], 'questionnaire-options') !== false
            &&
            $this->getProjectSetting('project-type') !== 'questionnaire'
        ){
            return false;
        }
        
        return $link;
    }

    // This method was mostly copied from data_dictionary_upload.php
    function replaceDataDictionaryWithQuestionnaire($file){
        $dictionaryPath = tempnam(sys_get_temp_dir(), 'fhir-questionnaire-data-dictionary-');
        
        try{
            $csv = $this->questionnaireToDataDictionary($file['tmp_name']);
            file_put_contents($dictionaryPath, $csv);

            require_once APP_PATH_DOCROOT . '/Design/functions.php';
            $dictionary_array = excel_to_array($dictionaryPath);
        }
        finally{
            unlink($dictionaryPath);
        }

        list ($errors_array, $warnings_array, $dictionary_array) = MetaData::error_checking($dictionary_array);

        $checkForErrors = function($errors, $type, $runOnError = null){
            if(empty($errors)){
                return false;
            }

            if($runOnError){
                $runOnError();
            }

            ?>
            <p>Uploading the questionnaire failed with the following <?=$type?>:</p>
            <pre><?=json_encode($errors)?></pre>
            <br>
            <?php
            
            throw new Exception("Uploading the questionnaire failed with the previously printed errors.");
        };

        $checkForErrors($errors_array, 'errors');
        $checkForErrors($warnings_array, 'warnings');

        // Set up all actions as a transaction to ensure everything is done here
        db_query("SET AUTOCOMMIT=0");
        db_query("BEGIN");
        
        // Create a data dictionary snapshot of the *current* metadata and store the file in the edocs table
        MetaData::createDataDictionarySnapshot();

        // Save data dictionary in metadata table
        $sql_errors = MetaData::save_metadata($dictionary_array);

        // Display any failed queries to Super Users, but only give minimal info of error to regular users
        $checkForErrors($sql_errors, 'errors', function(){
            // ERRORS OCCURRED, so undo any changes made
            db_query("ROLLBACK");
            // Set back to previous value
            db_query("SET AUTOCOMMIT=1");

            return;
        });

        // COMMIT CHANGES
        db_query("COMMIT");
        // Set back to previous value
        db_query("SET AUTOCOMMIT=1");

        $edocId = \Files::uploadFile($file, $this->getProjectId());
        $this->setProjectSetting('questionnaire', $edocId);
    }

    function getQuestionnaireEDoc(){
        $edocId = $this->getProjectSetting('questionnaire');
        if(!$edocId){
            return null;
        }

        $edocId = db_escape($edocId);
        $result = $this->query("select * from redcap_edocs_metadata where doc_id = $edocId");
        return $result->fetch_assoc();
    }

    function parse($data) {
        $parser = new PHPFHIRResponseParser();
        return $parser->parse($data);
    }
    
    function jsonSerialize($FHIRObject){
        if(empty($FHIRObject)){
            throw new Exception('A valid FHIR object must be specified.');
        }

        $a = json_decode(json_encode($FHIRObject->jsonSerialize()), true);
        
        $handle = function(&$a) use (&$handle){
            foreach($a as $key=>&$value){
                if($key[0] === '_'){
                    unset($a[$key]);
                    continue;
                }

                if(is_array($value)){
                    $handle($value);
                }
            }
        };

        $handle($a);

        return json_encode($a, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    }

    function xmlSerialize($FHIRObject){
        $dom = dom_import_simplexml($FHIRObject->xmlSerialize())->ownerDocument;
        $dom->formatOutput = true;
        return $dom->saveXML();
    }

    private function getPidFromSqlField($pid, $fieldName){
        if(empty($pid)){
            throw new Exception('A project id must be specified.');
        }

        $pid = db_escape($pid);
        $fieldName = db_escape($fieldName);

        $sql = "
            select element_enum
            from redcap_metadata
            where project_id = $pid
            and field_name = '$fieldName'
        ";

        $result = $this->query($sql);

        $row = $result->fetch_assoc();
        if($row === null){
            throw new Exception("Could not find the field named '$fieldName' for project $pid.");
        }

        if($result->fetch_assoc() !== null){
            throw new Exception("Multiple fields found!");
        }

        $sql = $row['element_enum'];
        preg_match("/project_id \= ([0-9]+)/", $sql, $matches);

        return $matches[1];
    }

    function getData($pid, $record){
        if(empty($record)){
            throw new Exception('A record ID is required.');
        }

        return json_decode(REDCap::getData($pid, 'json', $record), true);
    }

    function getFHIRUrlParts(){
        $fhirUrl = $_GET['fhir-url'];

        if(empty($fhirUrl)){
            $sendErrorResponse("You must specify a 'fhir-url' parameter.");
        }

        return explode('/', $fhirUrl);
    }

    function getProjectAndRecordIdsFromFHIRUrl(){
        $urlParts = $this->getFHIRUrlParts();
        $resourceId = $urlParts[2];
        $idParts = explode('-', $resourceId);
        $projectId = $idParts[0];
        $recordId = $idParts[1];

        if(
            empty($projectId)
            ||
            !ctype_digit($projectId)
            ||
            empty($recordId)
        ){
            $sendErrorResponse("The resource ID specified is not valid: $resourceId");
        }

        return [$projectId, $recordId]; 
    }

    function buildBundle($compositionsPid, $compositionId){
        list($compositionsPid, $compositionId) = $this->getProjectAndRecordIdsFromFHIRUrl();

        $practitionersPid = $this->getPidFromSqlField($compositionsPid, 'author_id');
        $studiesPid = $this->getPidFromSqlField($compositionsPid, 'subject_id');
        $organizationsPid = $this->getPidFromSqlField($studiesPid, 'sponsor_id');
    
        $compositionData = $this->getData($compositionsPid, $compositionId)[0];
        $authorData = $this->getData($practitionersPid, $compositionData['author_id'])[0]; 
        $studyData = $this->getData($studiesPid, $compositionData['subject_id'])[0];
        $piData = $this->getData($practitionersPid, $studyData['principal_investigator_id'])[0];
        
        $sponsorInstances = $this->getData($organizationsPid, $studyData['sponsor_id']);
        $sponsorContacts = [];
        foreach($sponsorInstances as $instance){
            $instrument = $instance['redcap_repeat_instrument'];
            if(empty($instrument)){
                $sponsorData = $instance;
            }
            else if($instrument === 'contacts'){
                $sponsorContacts[] = $instance;
            }
            else{
                throw new Exception("Unsupported repeating instrument: $instrument");
            }
        }
        
        $bundle = new FHIRBundle;

        $getReference = function ($o) use ($bundle){
            $id = $o->getId();
            if(empty($id)){
                throw new Exception('A reference cannot be created for an object without an id: ' . $this->jsonSerialize($o));
            }
        
            $existsInBundle = false;
            foreach($bundle->getEntry() as $entry){
                if($entry->getResource() === $o){
                    $existsInBundle = true;
                }
            }
            
            if(!$existsInBundle){
                throw new Exception("A reference cannot be created for an object that hasn't been added to the bundle!");
            }
        
            return new FHIRReference([
                'reference' => $o->_getFHIRTypeName() . "/$id"
            ]);
        };

        $addToBundle = function ($o) use ($bundle){
            $bundle->addEntry(new FHIRBundleEntry([
                'resource' => $o
            ]));

            return $o;
        };

        $sponsor = $addToBundle(new FHIROrganization([
            'id' => $sponsorData['organization_id'],
            'name' => $sponsorData['organization_name']
        ]));

        foreach($sponsorContacts as $contact){
            $sponsor->addContact(new FHIROrganizationContact([
                'name' => new FHIRHumanName([
                    'given' => $contact['contact_first_name'],
                    'family' => $contact['contact_last_name']
                ]),
                'telecom' => new FHIRContactPoint([
                    'system' => new FHIRContactPointSystem([
                        'value' => 'email'
                    ]),
                    'value' => $contact['contact_email']
                ])
            ]));
        }
        
        $pi = $addToBundle(new FHIRPractitioner([
            'id' => $piData['practitioner_id'],
            'name' => new FHIRHumanName([
                'given' => $piData['first_name'],
                'family' => $piData['last_name']
            ]),
            'telecom' => new FHIRContactPoint([
                'system' => new FHIRContactPointSystem([
                    'value' => 'email'
                ]),
                'value' => $piData['email']
            ])
        ]));
        
        $study = $addToBundle(new FHIRResearchStudy([
            'id' => $studyData['study_id'],
            'title' => $studyData['title'],
            'status' => new FHIRResearchStudyStatus([
                'value' => $studyData['status']
            ]),
            'principalInvestigator' => $getReference($pi),
            'sponsor' => $getReference($sponsor),
        ]));
        
        $compositionAuthor = $addToBundle(new FHIRPractitioner([
            'id' => $authorData['practitioner_id'],
            'name' => new FHIRHumanName([
                'given' => $authorData['first_name'],
                'family' => $authorData['last_name']
            ]),
            'telecom' => new FHIRContactPoint([
                'system' => new FHIRContactPointSystem([
                    'value' => 'email'
                ]),
                'value' => $authorData['email']
            ])
        ]));
        
        $addToBundle(new FHIRComposition([
            'id' => $compositionData['composition_id'],
            'type' => new FHIRCodeableConcept([
                'text' => $compositionData['type']
            ]),
            'author' => $getReference($compositionAuthor),
            'subject' => $getReference($study)
        ]));

        return $bundle;
    }

    function getQuestionnaireResponse(){
        list($projectId, $responseId) = $this->getProjectAndRecordIdsFromFHIRUrl();
        
        // $data = REDCap::getData($projectId, 'json', $responseId)[0];
        return new FHIRQuestionnaireResponse;
    }

    function saveQuestionnaire(){
        $input = file_get_contents('php://input');
        $q = $this->parse($input);
        $type = $q->_getFHIRTypeName();
        if($type !== 'Questionnaire'){
            throw new Exception("Expected a Questionnaire but found the following type instead: $type");
        }

        $logId = $this->log(QUESTIONNAIRE_RECEIVED, [
            'content' => $input
        ]);

        $q->setId("received-questionnaire-$logId");

        return $q;
    }

    function questionnaireToDataDictionary($questionnaire){
        $q = $this->parse(file_get_contents($questionnaire));

        $expectedResourceType = 'Questionnaire';
        $actualResourceType = $q->_getFHIRTypeName();
        if($actualResourceType !== $expectedResourceType){
            throw new Exception("Expected a resource type of '$expectedResourceType', but found '$actualResourceType' instead." . $q->resourceType);
        }
        
        $forms = [];
        $this->walkQuestionnaire($q, function($parent, $item) use (&$forms){
            $fieldName = $this->getFieldName($parent, $item);
            $instrumentName = $this->getInstrumentName($parent);
            if(empty($instrumentName)){
                $instrumentName = "top_level_questions";
            }

            $forms[$instrumentName][$fieldName] = [
                'type' => $this->getType($item),
                'label' => $this->getText($item),
                'choices' => $this->getChoices($item)
            ];
        });
        
        $out = fopen('php://memory', 'r+');
        fputcsv($out, ["Variable / Field Name","Form Name","Section Header","Field Type","Field Label","Choices, Calculations, OR Slider Labels","Field Note","Text Validation Type OR Show Slider Number","Text Validation Min","Text Validation Max","Identifier?","Branching Logic (Show field only if...)","Required Field?","Custom Alignment","Question Number (surveys only)","Matrix Group Name","Matrix Ranking?","Field Annotation"]);
        fputcsv($out, ['response_id', current(array_keys($forms)), '', 'text', 'Response ID']);

        foreach($forms as $formName=>$fields){
            foreach($fields as $name=>$field){
                fputcsv($out, [$name, $formName, '', $field['type'], $field['label'], $field['choices']]);
            }
        }

        rewind($out);

        return stream_get_contents($out);
    }

    function getChoices($item){
        if(empty($item->getAnswerOption())){
            return null;
        }

        $choices = [];
        foreach($item->getAnswerOption() as $option){
            $coding = $option->getValueCoding();
            $code = $this->getValue($coding->getCode());
            $display = $this->getValue($coding->getDisplay());
            
            $choices[] = "$code, $display";
        }

        return implode('|', $choices);
    }

    function getValue($fhirObject){
        return $fhirObject->getValue()->getValue();
    }

    function getFieldName($parent, $item){
        $n = $item->getLinkId()->getValue()->getValue();
        $n = strtolower($n);
        $n = ltrim($n, '/');
        $n = str_replace('/', '_', $n);
        $n = str_replace('.', '_', $n);
        $n = str_replace('[', '', $n);
        $n = str_replace(']', '', $n);

        if(ctype_digit($n[0])){
            // REDCap fields can't start with a number.
            $n = "q_$n";
        }

        return $n;
    }

    function questionnaireResponseToREDCapExport($path){
        $o = $this->parse(file_get_contents($path));

        $data = ['response_id' => 'TBD'];

        $handleObject = function($parent) use (&$handleObject, &$data){
            foreach($parent->getItem() as $item){
                $answers = $item->getAnswer();
                if(empty($answers)){
                    $handleObject($item);
                }
                else{
                    foreach($answers as $answer){
                        $data[$this->getFieldName($parent, $item)] = $this->getAnswerValue($item, $answer);
                    }
                }
            }
        };

        $handleObject($o);

        $out = fopen('php://memory', 'r+');

        fputcsv($out, array_keys($data));
        fputcsv($out, $data);

        rewind($out);

        return stream_get_contents($out);
    }

    function getAnswerValue($item, $answer){
        $v = $answer->getValueString()->getValue()->__toString();

        if($this->getText($item) === 'Last Updated at:'){
            $v = DateTime::createFromFormat('F j, Y \a\t g:i A e', $v)->format('Y-m-d H:i');
        }

        return $v;
    }

    function walkQuestionnaire($group, $fieldAction){
        $handleItems = function ($group) use (&$handleItems, &$out, &$fieldAction){
            $groupId = $this->getLinkId($group);

            foreach($group->getItem() as $item){
                $id = $item->getLinkId()->getValue()->getValue();
                if(in_array($item->getType()->getValue()->getValue()->getValue(), ['group', 'display'])){
                    if($groupId && strpos($id, $groupId) !== 0){
                        throw new Exception("The item ID ($id) does not start with it's parent group ID ($groupId)!  If this is expected then we'll need a different way to track parent/child relationships.");
                    }
                    
                    $handleItems($item);
                }
                else{
                    if($this->isRepeating($item)){
                        throw new Exception("The following field repeats, which is only supportted for groups currently: $id");
                    }
                    // else if($item->getText()->__toString() !== $item->getCode()[0]->getDisplay()->__toString()){
                    //     throw new Exception("Text & display differ: '{$item->getText()}' vs. '{$item->getCode()[0]->getDisplay()}'");
                    // }

                    $fieldAction($group, $item);
                } 
            }
        };

        $handleItems($group);
    }

    function isRepeating($item){
        if($item->_getFHIRTypeName() !== 'Questionnaire.Item'){
            return false;
        }

        $repeats = $item->getRepeats();
        return $repeats && $repeats->getValue()->getValue();
    }

    function getLinkId($item){
        if($item->_getFHIRTypeName() !== 'Questionnaire.Item'){
            return null;
        }

        return $item->getLinkId()->getValue()->getValue();
    }

    function getType($item){
        if($item->_getFHIRTypeName() === 'Questionnaire.Item'){
            $type = $item->getType();
            if($type){
                $type = $type->getValue()->getValue()->getValue();
                if(in_array($type, ['string', 'integer', 'decimal', 'dateTime'])){
                    $type = 'text';
                }
                else if($type === 'text'){
                    $type = 'notes';
                }
                else if(in_array($type, ['choice', 'open-choice'])){
                    return 'dropdown';
                }
                else{
                    throw new Exception("Type not supported: $type");
                }

                return $type;
            }
        }
    }

    function getText($item){
        $text = $item->getText();
        if($text){
            return $text->getValue()->getValue();
        }
    }

    function getInstrumentName($group){
        $name = strtolower($this->getText($group));
        $name = str_replace(' ', '_', $name);
        $name = str_replace('(', '', $name);
        $name = str_replace(')', '', $name);
        $name = str_replace(':', '', $name);
        $name = str_replace('?', '', $name);

        return $name;
    }

    function getSafePath($path, $root=null){
		$moduleDirectory = $this->getModulePath();
		if(!$root){
			$root = $moduleDirectory;
		}
		else if(!file_exists($root)){
			$root = "$moduleDirectory/$root";
		}

		if(!file_exists($root)){
			throw new Exception("The specified root ($root) does not exist as either an absolute path or a relative path to the module directory.");
		}

		$root = realpath($root);

		$fullPath = "$root/$path";

		if(file_exists($fullPath)){
			$fullPath = realpath($fullPath);
		}
		else{
			// Also support the case where this is a path to a new file that doesn't exist yet and check it's parents.
			$dirname = dirname($fullPath);
				
			if(!file_exists($dirname)){
				throw new Exception("The parent directory ($dirname) does not exist.  Please create it before calling getSafePath() since the realpath() function only works on directories that exist.");
			}

			$fullPath = realpath($dirname) . DIRECTORY_SEPARATOR . basename($fullPath);
		}

		if(strpos($fullPath, $root) !== 0){
			throw new Exception("You referenced a path ($fullPath) that is outside of your allowed parent directory ($root).");
		}

		return $fullPath;
    }
    
    function getQuestionnaireUrl($section){
        return $this->getUrl("questionnaire-options.php?section=$section");
    }

    function getReceivedQuestionnaires($whereClause = ''){
        return $this->queryLogs("
            select log_id, timestamp, content
            where project_id is null
            and message = '" . QUESTIONNAIRE_RECEIVED . "'
            $whereClause
            order by log_id desc
        ");
    }

    function getReceivedQuestionnaire($logId){
        $logId = db_escape($logId);
        $result = $this->getReceivedQuestionnaires("and log_id = $logId");
        return $result->fetch_assoc();
    }
}