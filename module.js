$(function(){
    var module = $.extend(FHIRServicesExternalModule, {
        init: function(){
            var elementTypeahead = module.initTypeahead({})
            var resourceTypeahead = module.initResourceTypeahead(elementTypeahead)

            module.RESOURCE_TYPEAHEAD = resourceTypeahead
            module.ELEMENT_TYPEAHEAD = elementTypeahead

            var addRow = function(label, field){
                var row = $('<div />')
                row.append('<label>' + label + ':</label>')
                row.append(field)

                typeaheadContainer.append(row)
            }

            var typeaheadContainer = $('<div id="fhir-services-mapping-field-settings" style="border: 1px solid rgb(211, 211, 211); padding: 4px 8px; margin-top: 5px; display: block;"><b>FHIR Mapping</b></div>')
            addRow('Resource', resourceTypeahead)
            addRow('Element', elementTypeahead)

            var openAddQuesFormVisible = window.openAddQuesFormVisible
            window.openAddQuesFormVisible = function(){
                openAddQuesFormVisible.apply(null, arguments);

                var details = module.getExistingActionTagDetails()
                if(!details){
                    // A error must have occurred.
                    return
                }
                
                if(details.value === ''){
                    /**
                     * The previously selected resource is intentionally left in place to make it
                     * easy to map several fields for the same resource in a row.
                     */
                    elementTypeahead.val('')
                    elementTypeahead.parent().hide()
                }
                else{
                    var parts = details.value.split('/')
                    resourceTypeahead.val(parts.shift())
                    elementTypeahead.val(parts.join('/'))

                    module.initElementAutocomplete()
                }

                if(resourceTypeahead.val() !== ''){
                    elementTypeahead.parent().show()
                }
            }

            $('#div_field_req').before(typeaheadContainer)
        },
        initTypeahead: function(options){
            options = $.extend({
                source: [],
                focus: function(){},
                blur: function(){}
            }, options)

            var typeahead = $('<input class="x-form-text x-form-field">')

            typeahead.focus(function(){
                options.focus(typeahead)

                $(function(){
                    typeahead.data("uiAutocomplete").search(typeahead.val());
                })
            })

            typeahead.blur(function(){
                options.blur(typeahead)

                var source = typeahead.autocomplete('option', 'source');
                if(typeof source[0] !== 'string'){
                    source = source.map(function(item){
                        return item.label
                    })
                }

                if(source.indexOf(typeahead.val()) === -1){
                    typeahead.val('')
                }
            })

            typeahead.keypress(function(e) {
                var code = (e.keyCode ? e.keyCode : e.which);
                if(code == 13) { //Enter keycode
                    // Ignore it
                    return false;
                }
            });

            typeahead.autocomplete({
                appendTo: '#div_add_field', // required for z-index to work properly
                source: options.source,
                minLength: 0,
                classes: {
                    'ui-autocomplete': 'fhir-services-module'
                },
                select: function(e, result){
                    typeahead.val(result.item.value)
                    typeahead.blur()
                }
            })
            .autocomplete( "instance" )._renderItem = function( ul, item ){
                var label = item.label

                if(item.description){
                    label = "<b>" + item.label + "</b><br>" + item.description
                }

                return $( "<li />" )
                    .append('<div>' + label + '</div>')
                    .appendTo( ul );
            }

            return typeahead
        },
        initResourceTypeahead: function(elementTypeahead){
            var resourceTypeAhead = module.initTypeahead({
                source: Object.keys(module.schema),
                blur: function(typeahead){
                    var elements = module.getElementsForResource()
                    if(elements){
                        module.initElementAutocomplete()
                        elementTypeahead.focus()
                    }
                    else{
                        elementTypeahead.parent().hide()
                    }
                }
            })

            elementTypeahead.blur(function(){
                var textarea = module.getActionTagTextArea()
                var tags = textarea.val()

                var details = module.getExistingActionTagDetails()
                if(!details){
                    // A error must have occurred.
                    return
                }

                var tagStartIndex = details.tagStartIndex
                var tagEndIndex = details.tagEndIndex

                var resource = resourceTypeAhead.val()
                var element = elementTypeahead.val()

                var newTag = ''
                if(resource != '' && element != ''){
                    newTag = module.ACTION_TAG_PREFIX + resource + '/' + element + module.ACTION_TAG_SUFFIX
                }

                if(tagStartIndex > 0 && tags[tagStartIndex-1] !== ' '){
                    newTag = ' ' + newTag
                }

                textarea.val(tags.substring(0, tagStartIndex) + newTag + tags.substring(tagEndIndex))
            })

            return resourceTypeAhead
        },
        initElementAutocomplete: function(){
            var elements = module.getElementsForResource()

            var options = []
            for(var path in elements){
                options.push({
                    label: path,
                    value: path,
                    description: elements[path].description
                })
            }

            module.ELEMENT_TYPEAHEAD.autocomplete('option', 'source', options)
            module.ELEMENT_TYPEAHEAD.parent().show()
        },
        getElementsForResource: function(){
            return module.schema[module.RESOURCE_TYPEAHEAD.val()]
        },
        ACTION_TAG_PREFIX: "@FHIR-MAPPING='",
        ACTION_TAG_SUFFIX: "'",
        getActionTagTextArea: function(){
            return $('#div_field_annotation textarea')
        },
        getExistingActionTagDetails(){
            var textarea = module.getActionTagTextArea()
            var tags = textarea.val()

            var tagPrefix = module.ACTION_TAG_PREFIX
            var tagStartIndex = tags.indexOf(tagPrefix)
            if(tagStartIndex === -1){
                tagStartIndex = tags.length
                tagEndIndex = tags.length
            }
            else{
                var tagEndIndex = tags.indexOf(module.ACTION_TAG_SUFFIX, tagStartIndex+tagPrefix.length)
                if(tagEndIndex === -1){
                    alert("Corrupt action tag detected.  Please remove what's left of the " + module.ACTION_TAG_PREFIX + module.ACTION_TAG_SUFFIX + " action tag.")
                    return false
                }
                else{
                    tagEndIndex++ // put it past the end of the tag
                }
            }

            return {
                tagStartIndex: tagStartIndex,
                tagEndIndex: tagEndIndex,
                value: tags.substring(tagStartIndex + tagPrefix.length, tagEndIndex-1)
            }
        }
    })

    module.init()
})