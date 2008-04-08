<?php
/*
 * Copyright (c) 2005-2008 WSO2, Inc. http://wso2.com
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or
 * implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * parse payload and return an array of paramerters
 * @param $payload 
 * @param $sig_node
 *      example of sig..
 *
 *      <params wrapper-element="myDemo"
 *             wrapper-element-ns="http://wso2.org/dyn/codegen/demo"
 *             simple="no" contentModel="all">
 *          <param token="#in"
 *               targetNamespace="http://wso2.org/dyn/codegen/demo"
 *               minOccurs="1" maxOccurs="1" name="demo1" type="int"
 *               type-namespace="http://www.w3.org/2001/XMLSchema" 
 *               type-prefix="xs" simple="yes"/>
 *          <param token="#in" 
 *               targetNamespace="http://wso2.org/dyn/codegen/demo"
 *               minOccurs="1" maxOccurs="1" name="demo2" type="string"
 *               type-namespace="http://www.w3.org/2001/XMLSchema" 
 *               type-prx="xs" simple="yes"/>
 *      </params>
 *
 *
 * @return the parse tree
 */
function wsf_parse_payload_for_array(DomNode $payload, DomNode $sig_node) {

    ws_log_write(__FILE__, __LINE__, WSF_LOG_DEBUG, "Loading in to parsing array");
    ws_log_write(__FILE__, __LINE__, WSF_LOG_DEBUG, wsf_test_serialize_node($sig_node));
    ws_log_write(__FILE__, __LINE__, WSF_LOG_DEBUG, wsf_test_serialize_node($payload));

    while($payload != NULL && $payload->nodeType != XML_ELEMENT_NODE) {
        $payload = $payload->nextSibling;
    }

    $parse_tree = array();

    if($sig_node->hasAttributes()) {
        //wrapped situations..    

        if($payload != NULL) {
            /* go for the childs */
            $current_child = $payload->firstChild;
            while($current_child != NULL && $current_child->nodeType != XML_ELEMENT_NODE) {
                $current_child = $current_child->nextSibling;
            }
            if($current_child == NULL) {
                return $parse_tree; //just the empty array
            }
        }

        $parse_tree = wsf_infer_content_model($current_child, $sig_node, NULL);

        return $parse_tree;
    }
    else {
        // this situation meets only for non-wrapped mode as doclit-bare wsdls
        $the_only_node = $sig_node->firstChild;

        $is_simple = FALSE;
        if($the_only_node->attributes->getNamedItem(WSF_WSDL_SIMPLE) &&
                $the_only_node->attributes->getNamedItem(WSF_WSDL_SIMPLE)->value == "yes") {
            $is_simple = TRUE;
        }
        $param_type = NULL;
        if($the_only_node->attributes->getNamedItem(WSF_TYPE)) {
            $param_type = $the_only_node->attributes->getNamedItem(WSF_TYPE)->value;
        }
        if($is_simple) {
            if ($payload !== NULL) {
                if($payload->firstChild) {
                    $original_value = $payload->firstChild->nodeValue;
                }
                else {
                    $original_value = "";
                }
                $converted_value = wsf_wsdl_deserialize_string_value($param_type, $original_value);

                return $converted_value;
            }
        }
    }

    return $parse_tree;

}


/**
 * parse payload and return an object hierarchy
 * @param $payload 
 * @param $sig_node
 *      example of sig..
 *
 *      <params wrapper-element="myDemo"
 *             wrapper-element-ns="http://wso2.org/dyn/codegen/demo"
 *             simple="no" contentModel="all">
 *          <param token="#in"
 *               targetNamespace="http://wso2.org/dyn/codegen/demo"
 *               minOccurs="1" maxOccurs="1" name="demo1" type="int"
 *               type-namespace="http://www.w3.org/2001/XMLSchema" 
 *               type-prefix="xs" simple="yes"/>
 *          <param token="#in" 
 *               targetNamespace="http://wso2.org/dyn/codegen/demo"
 *               minOccurs="1" maxOccurs="1" name="demo2" type="string"
 *               type-namespace="http://www.w3.org/2001/XMLSchema" 
 *               type-prx="xs" simple="yes"/>
 *      </params>
 *
 *
 * @param $element_name element name under which the parsing happen
 * @return the parsed result objects
 */
function wsf_parse_payload_for_class_map(DomNode $payload, DomNode $sig_node, $element_name, $classmap) {

    ws_log_write(__FILE__, __LINE__, WSF_LOG_DEBUG, "Loading in to parsing classmap");
    ws_log_write(__FILE__, __LINE__, WSF_LOG_DEBUG, wsf_test_serialize_node($sig_node));
    ws_log_write(__FILE__, __LINE__, WSF_LOG_DEBUG, wsf_test_serialize_node($payload));

    while($payload != NULL && $payload->nodeType != XML_ELEMENT_NODE) {
        $payload = $payload->nextSibling;
    }

    if($sig_node->hasAttributes()) {
        //wrapped situations..    

        // this situation gotta create the object..
        if(is_array($classmap) && array_key_exists($element_name, $classmap)) {
            $class_name = $classmap[$element_name];
        }
        if(!isset($class_name) || $class_name == NULL) {
            $class_name = $element_name;
        }

        try {
            $ref_class = new ReflectionClass($class_name);
            if ($ref_class->isInstantiable()) {
                $object = $ref_class->newInstance();

            }
        } catch(Exception $e) {
            $object = new WSFUnknownSchemaConstruct();
        }

        if($object == NULL) {
            return NULL;
        }

        // for a NULL payload return the empty object
        if($payload == NULL) {
            return $object;
        }

        $current_child = $payload->firstChild;
        while($current_child != NULL && $current_child->nodeType != XML_ELEMENT_NODE) {
            $current_child = $current_child->nextSibling;
        }
        if($current_child == NULL) {
            /* it still can be a text node */
            $current_child = $payload->firstChild;
            if($current_child != NULL && $current_child->nodeType == XML_TEXT_NODE) {
                return $current_child->nodeValue;
            }
            /* otherwise it is a NULL */
            return NULL;
        }

        // all the above part of the story we were handling non-wrap types like in doclit-bare

        $parse_tree = wsf_infer_content_model($current_child, $sig_node, $classmap);

        foreach($parse_tree as $parsed_key => $parsed_value) {
            $object->$parsed_key = $parsed_value;
        }

        ws_log_write(__FILE__, __LINE__, WSF_LOG_DEBUG, print_r($object, TRUE));
        return $object;

    }
    else {
        // this situation meets only for non-wrapped mode as doclit-bare wsdls
        $the_only_node = $sig_node->firstChild;

        $is_simple = FALSE;
        if($the_only_node->attributes->getNamedItem(WSF_WSDL_SIMPLE) &&
                $the_only_node->attributes->getNamedItem(WSF_WSDL_SIMPLE)->value == "yes") {
            $is_simple = TRUE;
        }
        $param_type = NULL;
        if($the_only_node->attributes->getNamedItem(WSF_TYPE)) {
            $param_type = $the_only_node->attributes->getNamedItem(WSF_TYPE)->value;
        }
        if($is_simple) {
            if ($payload !== NULL) {
                if($payload->firstChild) {
                    $original_value = $payload->firstChild->nodeValue;
                }
                else {
                    $original_value = "";
                }
                $converted_value = wsf_wsdl_deserialize_string_value($param_type, $original_value);

                return $converted_value;
            }
        }
    }

    return NULL;
}


/**
 * wsf_parse_payload_for_unknown_array
 * @param $current_node node to parse
 * @returns array of parsed data
 */
function wsf_parse_payload_for_unknown_array(DomNode $current_node) {
    $param_child = array();

    while($current_node != NULL && $current_node->nodeType != XML_ELEMENT_NODE) {
        $current_node = $current_node->nextSibling;
    }

    foreach($current_node->childNodes as $child) {
        if($child->nodeType == XML_TEXT_NODE) {
            $node_value = $child->nodeValue;
            return $node_value;
        }


        $name = $child->tagName;
        $needle = strpos($name, ':');

        if($needle !== FALSE) {
            $name = substr($name, $needle + 1);
        }

        if($child->nodeType ==  XML_ELEMENT_NODE) {
            $node_value = wsf_parse_payload_for_unknown_array($child);
        }
        else
        {
            $node_value = NULL;
        }

        if($node_value !== NULL) {
            if($param_child[$name] === NULL) {
                $param_child[$name] = $node_value;
            }
            else
            {
                if(is_array($param_child[$name]) && !wsf_is_map($param_child[$name])) {
                    $i = count($param_child[$name]);
                    $param_child[$name][$i] = $node_value;
                }
                else
                {
                    $tmp = $param_child[$name];
                    $param_child[$name] = array($tmp, $node_value);
                }
            }
        }
    }

    return $param_child;
}

/**
 * wsf_parse_payload_for_unknown_class_map
 * @param $current_node node to parse
 * @returns array of parsed data
 */
function wsf_parse_payload_for_unknown_class_map(DomNode $current_node, $element_name, $classmap) {
    $class_name = NULL;
    if(is_array($classmap) && array_key_exists($element_name, $classmap)) {
        $class_name = $classmap[$element_name];
    }
    if(!isset($class_name) || $class_name == NULL) {
        $class_name = $element_name;
    }
   
    try
    {
        $ref_class = new ReflectionClass($class_name);
        if ($ref_class->isInstantiable()) {
            $object = $ref_class->newInstance();

        }
    } catch(Exception $e) {
        $object = new WSFUnknownSchemaConstruct();
    }

    if($object == NULL) {
        return NULL;
    }

    while($current_node != NULL && $current_node->nodeType != XML_ELEMENT_NODE) {
        $current_node = $current_node->nextSibling;
    }

    foreach($current_node->childNodes as $child) {
        if($child->nodeType == XML_TEXT_NODE) {
            $node_value = $child->nodeValue;
            return $node_value;
        }


        $name = $child->tagName;
        $needle = strpos($name, ':');

        if($needle !== FALSE) {
            $name = substr($name, $needle + 1);
        }

        if($child->nodeType ==  XML_ELEMENT_NODE) {
            $node_value = wsf_parse_payload_for_unknown_class_map($child, $name, $classmap);
        }
        else
        {
            $node_value = NULL;
        }

        if($node_value !== NULL) {
            if($object->$name === NULL) {
                $object->$name = $node_value;
            }
            else
            {
                if(is_array($object->$name) && !wsf_is_map($object->$name)) {
                    $i = count($object->$name);
                    $tmp = $object->$name;
                    $tmp[$i] = $node_value;
                    $object->$name = $tmp;
                }
                else
                {
                    $tmp = $object->$name;
                    $object->$name = array($tmp, $node_value);
                }
            }
        }
    }

    return $object;
}

/** 
 * Do deserialization over simple schema types and move to the next element
 * @param $current_child is the relevent xml node to parse, and 
 *                   move the current_child to the next child
 * @param $sig_param_node the sig model for the param
 * @return the result simple type value.
 */

function deserialize_simple_types(&$current_child, DomNode $sig_param_node) {

    $ret_val = NULL;
    $target_namespace = NULL;
    $min_occurs = 1;
    $max_occurs = 1;
    $sig_param_attris = $sig_param_node->attributes;
    $param_type = NULL;
    $param_name = NULL;

    if($sig_param_attris->getNamedItem(WSF_NAME)) {
        $param_name =
            $sig_param_attris->getNamedItem(WSF_NAME)->value;
    }

    if($sig_param_attris->getNamedItem(WSF_TARGETNAMESPACE)) {
         $target_namespace =
            $sig_param_attris->getNamedItem(WSF_TARGETNAMESPACE)->value;
    }

    if($sig_param_attris->getNamedItem(WSF_MIN_OCCURS)) {
         $min_occurs =
            $sig_param_attris->getNamedItem(WSF_MIN_OCCURS)->value;
    }

    if($sig_param_attris->getNamedItem(WSF_MAX_OCCURS)) {
         $max_occurs =
            $sig_param_attris->getNamedItem(WSF_MAX_OCCURS)->value;
    }

    if($sig_param_attris->getNamedItem(WSF_TYPE)) {
         $param_type =
            $sig_param_attris->getNamedItem(WSF_TYPE)->value;
    }

    if($max_occurs > 1 || $max_occurs == "unbounded") {
        $i = 0;
        $tmp_array = array();
        while($current_child !== NULL && $current_child->localName == $param_name) {
            if($current_child->firstChild) {
                $converted_value =  wsf_wsdl_deserialize_string_value($param_type, $current_child->firstChild->wholeText);
            }
            else{
                if(!isset($param_value["nillable"])) {
                    error_log("Non nillable element". $param_name ."is nil. \n");
                    ws_log_write(__FILE__, __LINE__, WSF_LOG_DEBUG, "Non nillable element". $param_name ."is nil. ");
                }
                $converted_value = "";
            }
            $tmp_array[$i++] = $converted_value;
            $current_child = $current_child->nextSibling;
            while($current_child != NULL && $current_child->nodeType != XML_ELEMENT_NODE) {
                $current_child = $current_child->nextSibling;
            }
        }
        $ret_val = $tmp_array;
        $object->$param_name = $tmp_array;
        
    }
    else {
        if($current_child->firstChild) {
            $converted_value =  wsf_wsdl_deserialize_string_value($param_type, $current_child->firstChild->wholeText);
        }
        else
        {
            if(!isset($param_value["nillable"])) {
                error_log("Non nillable element". $param_name ."is nil. \n");
                ws_log_write(__FILE__, __LINE__, WSF_LOG_DEBUG, "Non nillable element". $param_name ."is nil. ");
            }
            $converted_value = "";
        }
        $object->$param_name = $converted_value;
        $ret_val = $converted_value;
        $current_child = $current_child->nextSibling;
        while($current_child != NULL && $current_child->nodeType != XML_ELEMENT_NODE) {
            $current_child = $current_child->nextSibling;
        }
    }
    return $ret_val;
}

/** 
 * Do deserialization over complex schema types and move to the next element
 * @param $current_child is the relevent xml node to parse, and 
 *                   move the current_child to the next child
 * @param $sig_param_node the sig model for the param
 *
 * @param $classmap the classmap passed by the user
 * @return the result complex type value.
 */
function deserialize_complex_types(&$current_child, DomNode $sig_param_node, $classmap) {

    $ret_val = NULL;
    $target_namespace = NULL;
    $min_occurs = 1;
    $max_occurs = 1;
    $sig_param_attris = $sig_param_node->attributes;
    $param_type = NULL;
    $param_name = NULL;

    if($sig_param_attris->getNamedItem(WSF_NAME)) {
        $param_name =
            $sig_param_attris->getNamedItem(WSF_NAME)->value;
    }

    if($sig_param_attris->getNamedItem(WSF_TARGETNAMESPACE)) {
         $target_namespace =
            $sig_param_attris->getNamedItem(WSF_TARGETNAMESPACE)->value;
    }

    if($sig_param_attris->getNamedItem(WSF_MIN_OCCURS)) {
         $min_occurs =
            $sig_param_attris->getNamedItem(WSF_MIN_OCCURS)->value;
    }

    if($sig_param_attris->getNamedItem(WSF_MAX_OCCURS)) {
         $max_occurs =
            $sig_param_attris->getNamedItem(WSF_MAX_OCCURS)->value;
    }

    if($sig_param_attris->getNamedItem(WSF_TYPE)) {
         $param_type =
            $sig_param_attris->getNamedItem(WSF_TYPE)->value;
    }

    if($max_occurs > 1 || $max_occurs == "unbounded") {
        $i = 0;
        $tmp_array = array();
        while($current_child !== NULL && $current_child->localName == $param_name) {
            if($current_child->firstChild) {
                if($sig_param_node->hasChildNodes()) {
                    if($classmap) {
                        $converted_value = wsf_parse_payload_for_class_map($current_child, $sig_param_node, $param_type, $classmap);
                    }
                    else { 
                        $converted_value = wsf_parse_payload_for_array($current_child, $sig_param_node);
                    }

                }
                else
                {
                    if($classmap) {
                        $converted_value = wsf_parse_payload_for_unknown_class_map($current_child, $param_type, $classmap);
                    }
                    else {
                        $converted_value = wsf_parse_payload_for_unknown_array($current_child);
                    }
                }
            }
            else
            {
                if(!isset($param_value["nillable"])) {
                    error_log("Non nillable element". $param_name ."is nil. \n");
                    ws_log_write(__FILE__, __LINE__, WSF_LOG_DEBUG, "Non nillable element". $param_name ."is nil. ");
                }
                $converted_value = "";
            }
            $tmp_array[$i++] = $converted_value;
            $current_child = $current_child->nextSibling;
            while($current_child != NULL && $current_child->nodeType != XML_ELEMENT_NODE) {
                $current_child = $current_child->nextSibling;
            }
        }
        $ret_val = $tmp_array;
    }
    else {
        if($current_child->firstChild) {
            if($sig_param_node->hasChildNodes()) {
                if($classmap) {
                    $converted_value = wsf_parse_payload_for_class_map($current_child, $sig_param_node, $param_type, $classmap);
                }
                else { 
                    $converted_value = wsf_parse_payload_for_array($current_child, $sig_param_node);
                }
            }
            else
            {
                if($classmap) {
                    $converted_value = wsf_parse_payload_for_unknown_class_map($current_child, $param_type, $classmap);
                }
                else{
                    $converted_value = wsf_parse_payload_for_unknown_array($current_child);
                }
            }
        }
        else
        {
            if(!isset($param_value["nillable"])) {
                error_log("Non nillable element". $param_name ."is nil. \n");
                ws_log_write(__FILE__, __LINE__, WSF_LOG_DEBUG, "Non nillable element". $param_name ."is nil. ");
            }
            $converted_value = "";
        }
        $ret_val = $converted_value;
        $current_child = $current_child->nextSibling;
        while($current_child != NULL && $current_child->nodeType != XML_ELEMENT_NODE) {
            $current_child = $current_child->nextSibling;
        }
    }
    return $ret_val;
}

/**
 * handle content model in parsing
 * @param $current_child, starting child element to handle...
 * @param $sig_node the sig model for the content model 
 *
 * @param $classmap the user passed classmap
 */
function wsf_infer_content_model(DomNode $current_child, DomNode $sig_node, $classmap) {

    $parse_tree = array();

    $content_model = WSF_WSDL_SEQUENCE;
    
    if($sig_node->attributes->getNamedItem(WSF_CONTENT_MODEL)) {
        $content_model = $sig_node->attributes->getNamedItem(WSF_CONTENT_MODEL)->value;
    }

    $first_child = $current_child;

    if($sig_node->hasChildNodes()) {
        foreach($sig_node->childNodes as $sig_param_node) {

            if($sig_param_node->nodeName == WSF_PARAM) {
                $is_simple = FALSE;
                $param_name = NULL;
                $param_type = NULL;
                $min_occurs = 1;

                if($sig_param_node->attributes->getNamedItem(WSF_NAME)) {
                    $param_name = $sig_param_node->attributes->getNamedItem(WSF_NAME)->value;
                }
                if($sig_param_node->attributes->getNamedItem(WSF_TYPE)) {
                    $param_type = $sig_param_node->attributes->getNamedItem(WSF_TYPE)->value;
                }
                if($sig_param_node->attributes->getNamedItem(WSF_MIN_OCCURS)) {
                    $min_occurs = $sig_param_node->attributes->getNamedItem(WSF_MIN_OCCURS)->value;
                }
                if($sig_param_node->attributes->getNamedItem(WSF_WSDL_SIMPLE) &&
                        $sig_param_node->attributes->getNamedItem(WSF_WSDL_SIMPLE)->value == "yes") {
                    $is_simple = TRUE;
                }

                // for any types no content model can be specified..
                if($param_type == "anyType") {
                    $tag_name = $current_child->localName;
                    if($param_name == $tag_name) {
                        $converted_value = wsf_parse_payload_for_unknown_array($current_child);
                        $parse_tree[$param_name] = $converted_value;
                        continue;
                    }
                }

                if($content_model == WSF_WSDL_SEQUENCE) {
                        ws_log_write(__FILE__, __LINE__, WSF_LOG_DEBUG, 
                                "\$param_name:{$param_name} and child name: {$current_child->localName}");
                    if($param_name == $current_child->localName) {

                        if($is_simple) {
                            // this moves the current_child pointer to the next child..
                            $parse_tree[$param_name] = deserialize_simple_types($current_child, $sig_param_node);
                        }
                        else {
                            $parse_tree[$param_name] = deserialize_complex_types($current_child, $sig_param_node, $classmap);
                        }
                    }
                    else
                    {
                        if($min_occurs == 0) {
                            error_log("minOccurs != 0 element ". $param_name ." doesn't exist in the sequence.\n");
                            ws_log_write(__FILE__, __LINE__, WSF_LOG_DEBUG, "minOccurs != 0 element ". $param_name ." doesn't exist in the sequence.");
                            if($current_child->localName != NULL){
                                error_log($current_child->localName. " is found in place of ". $param_name ."\n");
                                ws_log_write(__FILE__, __LINE__, WSF_LOG_DEBUG, $current_child->localName. " is found in place of ". $param_name);
                            }
                        }
                    }
                }
                else if($content_model == WSF_WSDL_ALL) {
                    $found = FALSE;
                    $current_child = $first_child;

                    while($current_child !== NULL) {
                        if($current_child->nodeType == XML_TEXT_NODE) {
                            continue;
                        }
                        if($param_name == $current_child->localName) {
                            if($is_simple) {
                                // this moves the current_child pointer to the next child..
                                $parse_tree[$param_name] = deserialize_simple_types($current_child, $sig_param_node);
                            }
                            else {
                                $parse_tree[$param_name] = deserialize_complex_types($current_child, $sig_param_node, $classmap);
                            }
                            $found = TRUE;
                            break; //found and no need to investigate more..
                        }

                        $current_child = $current_child->nextSibling;
                    }
                    if(!$found) {
                        if($min_occurs == 0) {
                            // if array_key doesn't exist that mean minOccurs = 1;
                            error_log("minOccurs != 0 element ". $param_name ." doesn't exist.\n");
                            ws_log_write(__FILE__, __LINE__, WSF_LOG_DEBUG, "minOccurs != 0 element ". $param_name ." doesn't exist.");
                        }  
                    }
                }
                else if($content_model == "choice") {
                    if($param_name == $current_child->localName) {
                        if($is_simple) {
                            // this moves the current_child pointer to the next child..
                            $parse_tree[$param_name] = deserialize_simple_types($current_child, $sig_param_node);
                        }
                        else {
                            $parse_tree[$param_name] = deserialize_complex_types($current_child, $sig_param_node, $classmap);
                        }

                        //finally found what they were asking for, no need to continue any further..
                        break;
                    }
                }
            }
            else if($sig_param_node->nodeName == WSF_INNER_CONTENT) {
                $tmp_tree = wsf_infer_content_model($current_child, $sig_param_node, $classmap);
                $parse_tree = array_merge($parse_tree, $tmp_tree);
            }
            else if($sig_param_node->nodeName == WSF_INHERITED_CONTENT) {
                $tmp_tree = wsf_infer_content_model($current_child, $sig_param_node, $classmap);
                $parse_tree = array_merge($parse_tree, $tmp_tree);
            }
        }
    }
 
    return $parse_tree;
}


/**
 * deserialize the php value from the string value to the given xsd type value
 * @param $xsd_type, xsd type the value hold
 * @param $data_value, the data_value with the string type
 * @return deserialized to given php type value
 */
function wsf_wsdl_deserialize_string_value($xsd_type, $data_value) {
    $xsd_php_mapping_table = wsf_wsdl_util_xsd_to_php_type_map();

    ws_log_write(__FILE__, __LINE__, WSF_LOG_DEBUG, "deserializing ".$data_value);

    $converted_value = $data_value;
    if(array_key_exists($xsd_type, $xsd_php_mapping_table)) {
        $type = $xsd_php_mapping_table[$xsd_type];
        if($type == 'integer') {
            $converted_value = (int)($data_value);
        }
        else if ($type == 'float') {
            $converted_value = (float)($data_value);
        }
        else if ($type == 'boolean') {
            $converted_value = ($data_value === "true");
        }
        else if ($type == 'string') {
            $converted_value = $data_value;
        }
        else {
            $converted_value = $data_value;
        }
    }
    
    ws_log_write(__FILE__, __LINE__, WSF_LOG_DEBUG, "deserialized to ".$converted_value);

    return $converted_value;
}


//-----------------------------------------------------------------------------


?>