<?php namespace Vanderbilt\FHIRServicesExternalModule;

use REDCap;

class FieldMapper{
    function __construct($module, $projectId, $recordId){
        $this->module = $module;
        $this->projectId = $projectId;
        $this->recordId = $recordId;
        $this->resources = [];
        $this->contactPoints = [];

        $mappings = $this->getMappings($projectId);

        // Add the record ID regardless so that the standard return format is used.
        // REDCap returns a different format without it.
        $fields = array_merge([$this->getModule()->getRecordIdField($projectId)], array_keys($mappings));
        $data = json_decode(REDCap::getData($projectId, 'json', $recordId, $fields), true)[0];
        foreach($data as $fieldName=>$value){
            $mapping = @$mappings[$fieldName];
            if($value === '' || $mapping === null){
                continue;
            }

            $this->processElementMapping($fieldName, $value, $mapping);
        }

        $this->formatContactPoints($this->contactPoints);

        return $this->resources;
    }

    private function getModule(){
        return $this->module;
    }
    
    private function getProjectId(){
        return $this->projectId;
    }

    private function getRecordId(){
        return $this->recordId;
    }

    public function getResources(){
        return $this->resources;
    }

    private function getMappings($projectId){
        $metadata = REDCap::getDataDictionary($projectId, 'array');
        $mappings = [];
        foreach($metadata as $fieldName=>$details){
            $pattern = '/.*' . ACTION_TAG_PREFIX . '(.*)' . ACTION_TAG_SUFFIX . '.*/';
            preg_match($pattern, $details['field_annotation'], $matches);
            if(!empty($matches)){
                $value = $matches[1];
                $parts = explode('/', $value);
                $parsedMapping = [
                    'raw' => $value,
                    'resource' => array_shift($parts),
                    'elementPath' => implode('/', $parts),
                    'elementName' => array_pop($parts),
                    'elementParents' => $parts
                ]; 
                
                $mappings[$fieldName] = $parsedMapping;     
            }
        }

        return $mappings;
    }

    private function processElementMapping($fieldName, $value, $mapping){
        $definitions = SchemaParser::getDefinitions();

        $resourceName = $mapping['resource'];
        $elementName = $mapping['elementName'];

        if(!isset($this->resources[$resourceName])){
            $this->resources[$resourceName] = [
                'resourceType' => $resourceName,
                'id' => $this->getModule()->getRecordFHIRId($this->getProjectId(), $this->getRecordId())
            ];
        }

        $resource = &$this->resources[$resourceName];
        
        $subPath = &$resource;
        $parentProperty = [
            'type' => null
        ];
        $parentDefinition = $definitions[$resourceName];

        foreach($mapping['elementParents'] as $parentName){
            $subPath = &$subPath[$parentName];
            $parentProperty = $parentDefinition['properties'][$parentName];
            $subResourceName = SchemaParser::getResourceNameFromRef($parentProperty);
            $parentDefinition = $definitions[$subResourceName];

            if($subResourceName === 'ContactPoint'){
                $this->contactPoints[] = &$subPath;
            }
            else if($parentProperty['type'] === 'array'){
                // We only support one mapping of each element for now, so just always use the first instance of any array.
                $subPath =& $subPath[0];
            }
        }

        $elementProperty = $parentDefinition['properties'][$elementName];
        if($elementProperty['type'] !== 'array' && isset($subPath[$elementName])){
            if(
                $resourceName === 'Patient'
                &&
                $mapping['elementPath'] === 'deceasedBoolean'
            ){
                /**
                 * This element is allowed to be mapped multiple times, since a deceased flag may exist on multiple events in REDCap.
                 * If ANY of those mapped values is true, then we should just continue and ignore any false values.
                 * Remember, this loop may not process events in chronological order.
                 */
                if(@$subPath[$elementName] === true){
                    return;
                }
            }
            else{
                throw new StackFreeException("The '" . $mapping['raw'] . "' element is currently mapped to multiple fields, but should only be mapped to a single field.  It is recommended to download the Data Dictionary and search for '" . $mapping['raw'] . "' to determine which field mapping(s) need to be modified.");
            }
        }

        // The java FHIR validator does not allow leading or trailing whitespace.
        $value = trim($value);
        if($value === ''){
            // In FHIR, empty values should just not be specified in the first place.
            return;
        }

        /**
         * This can't currently be combined with $elementProperty above
         * because of special handling (pseudo-elements like Patient/telecom/mobile/phone/value).
         */
        $modifiedElementProperty = SchemaParser::getModifiedProperty($resourceName, $mapping['elementPath']);

        $choices = $modifiedElementProperty['redcapChoices'];
        if($choices !== null){
            $value = $this->getMatchingChoiceValue($this->getProjectId(), $fieldName, $value, $choices);
            
            $elementResourceName = SchemaParser::getResourceNameFromRef($elementProperty);
            if($elementResourceName === 'CodeableConcept'){
                $value = [
                    'coding' => [
                        [
                            'system' => $modifiedElementProperty['systemsByCode'][$value],
                            'code' => $value
                        ]
                    ]
                ];
            }
        }
        else if($modifiedElementProperty['type'] === 'boolean'){
            if($value === 'true' || $value === '1'){
                $value = true;
            }
            else if($value === 'false' || $value === '0'){
                $value = false;
            }
        }
        else if($modifiedElementProperty['pattern'] === DATE_TIME_PATTERN){
            $value = $this->getModule()->formatFHIRDateTime($value);
        }

        if($elementProperty['type'] === 'array'){
            $subPath[$elementName][] = $value;
        }
        else{
            $subPath[$elementName] = $value;
        }
    }

    private function formatContactPoints($contactPoints){
        foreach($contactPoints as &$contactPoint){
            if(isset($contactPoint[0])){
                // We've already formatted this ContactPoint
                continue;
            }

            foreach($contactPoint as $use=>$systems){
                unset($contactPoint[$use]);
                foreach($systems as $system=>$values){
                    $contactPoint[] = array_merge([
                        'use' => $use,
                        'system' => $system,
                    ], $values);
                }
            }
        }
    }

    private function getMatchingChoiceValue($projectId, $fieldName, $value, $choices){
        // An example of a case where it makes sense to match the label instead of the value is a REDCap gender value of 'F' with a label of 'Female'.
        $label = strtolower($this->getModule()->getChoiceLabel(['project_id'=>$projectId, 'field_name'=>$fieldName, 'value'=>$value]));
        $possibleValues = [$value, $label];

        $lowerCaseMap = [];
        foreach($choices as $code => $label){
            $lowerCaseMap[strtolower($label)] = $code;
            $lowerCaseMap[strtolower($code)] = $code;
        }

        foreach($possibleValues as $possibleValue){
            // Use lower case strings for case insensitive matching.
            $matchedValue = @$lowerCaseMap[strtolower($possibleValue)];
            if($matchedValue !== null){
                return $matchedValue;
            }
        }

        return $value;
    }
}