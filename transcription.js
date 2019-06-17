var select2Data;
var parserRecord;
var parserType;

var lastInput = null;

jQuery(function (){
	jQuery("#atlasSelection").val(-1);
	jQuery("#mode").val("first");
	jQuery("#region").val("");
	
	jQuery('.infoSymbol').qtip();
	
	layout();
	
	jQuery(".helpIcon").each(addUpperQTips);
	
	select2Data = getConceptSearchDefaults();
	select2Data["data"] = Concepts;
	select2Data["matcher"] = va_matcher; //TODO
	
	jQuery("select:not(.conceptList, #mapSelection)").select2();
	jQuery("#mapSelection").select2({
		templateResult: function (state){
			if(state.color){
				var colString = " style='background-color: " + state.color + ";'";
			}
			return jQuery("<span" + colString + ">" + state.text + "</span>");
		},
		matcher : va_matcher, //TODO
		ajax : {
			url : ajaxurl,
			dataType : "json",
			data : function (params){
				return create_ajax_data({
					"query" : "get_map_list",
					"atlas" : jQuery("#atlasSelection").val(),
					"search": params.term
				});
			},
			delay: 250
		},
		placeholder : Translations.SEARCH_MAP
	});
	
	jQuery("#atlasSelection").change (atlasChanged);
	jQuery("#mapSelection").change(function (){
		mapChanged(jQuery("#mapSelection").val());
	});
	jQuery("#mode").change(function (){
		jQuery("#region").val("");
		ajax_info();
	});
	jQuery("#region").change(ajax_info);
	
	jQuery("#informant_filters").qtip({
		content : {
			text : jQuery("#informant_filter_screen"),
			title: {
				button: true // Close button
			}
		},
		show: "click",
		hide: "click",
		position : {
			my: "bottom right",
			at: "top left"
		}
	});
	
	jQuery("#informant_filter_screen input[data-selected=1]").prop("checked", true);
	
	jQuery("#informant_filter_screen input").change(ajax_info);
	
	jQuery("td.imageTranscriptionRule").click(clickOnRule);
	
	jQuery(document).on("focus", ".inputStatement", function (){
		lastInput = this;
	});
	
	jQuery("#addRow").click(function (){
		var data = create_ajax_data({
			"query" : "get_new_row",
			"index" : ++State.Index
		});
		
		jQuery.post(ajaxurl, data, function (response){
			jQuery("#inputTable").append(response);
			
			fixTdWidths();
			
			getField("inputStatement", State.Index).focus();
			getField("classification", State.Index).val(getField("classification", 0).val());

			addRowJS(data.index);
			getField("conceptList", State.Index).val(getField("conceptList", 0).val()).trigger("change");

		});
	});
	
	jQuery("#insertAttestation").click(function (){
    	writeAttestation();
    });
	
	jQuery(".tt_extra_button").click(function (){
		writeAttestation("<" + jQuery(this).data("dbval") + ">");
	});
	
	var backspaceIsPressed = false;
    jQuery(document).keydown(function(event){
        if (event.which == 8) {
            backspaceIsPressed = true;
            
        }
    });
    jQuery(document).keyup(function(event){
        if (event.which == 8) {
            backspaceIsPressed = false;
        }
    });
    
    jQuery(window).on('beforeunload', function(){
        if (backspaceIsPressed) {
            backspaceIsPressed = false;
            return "Are you sure you want to leave this page?";
        }
    });
    
    jQuery("#addConcept").click(function (){
    	showTableEntryDialog("newConceptDialog", function (paramData){
    		var text = paramData["Name_D"]? paramData["Name_D"] : paramData["Beschreibung_D"];
    		insertConcept(paramData["id"], text);
    	}, selectModes.Select2);
    });
    
    addNewEnumValueScript ("#newConceptDialog select", selectModes.Select2);
    
    jQuery(document).on("keyup paste", ".inputStatement", updateOriginal);
    
    jQuery(document).on("change", ".classification", function (){
    	updateOriginal.bind(getField("inputStatement", jQuery(this).closest("tr").data("index")))();
    });
    
    jQuery(window).on('unload', function(){
    	removeTTLock (true);
    });
    
    if (URLData["atlas"]){
    	jQuery("#atlasSelection").val(URLData["atlas"]).trigger("change");
//    	
//    	if (URLData["stimulus"]){
//    		jQuery("#mapSelection option").each(function (){
//    			if (jQuery(this).)
//    		});
//    		
//    		
//    		jQuery("#mapSelection").val(URLData["stimulus"]).trigger("change");
//    	}
    }
});

function removeTTLock (pageClosed, callback){
	var data = create_ajax_data({
		"query" : "remove_lock",
		"context" : "Transcription"
	});
	
	if(pageClosed){
		//Callback is ignored in this case
		var fd = new FormData();
		for (key in data){
			fd.append(key, data[key]);
		}
		
		navigator.sendBeacon(ajaxurl, fd);
	}
	else {
		jQuery.post(ajaxurl, data, callback);
	}
}

function addTTLock (value, callback){
	var data = create_ajax_data({
		"query" : "add_lock",
		"context" : "Transcription",
		"value" : value
	});
	
	jQuery.post(ajaxurl, data, callback);
}

function updateOriginal (){
	var thisObject = this;
	setTimeout(function () { //Timeout is needed since the paste event is fired before the input value has changed
		try {
			content = convertToOriginal(jQuery(thisObject).val(), jQuery(thisObject).closest("tr").find(".classification").val() != "B");
		}
		catch (e){
			content = "<span style='color: red'>" + Translations.NOT_VALID + "</span>";
		}
		
		jQuery(thisObject).next().html(content);
	}, 0);
}

function clickOnRule (){

	if(!jQuery("#input_fields").hasClass("hidden_c")){
		var beta = jQuery(this).parent().find("td.betaTranscriptionRule").text().replace("␣", " ");
		if (jQuery(".inputStatement").length == 1 && !jQuery(".inputStatement").is(":disabled")){
			jQuery(".inputStatement").val(jQuery(".inputStatement").val() + beta);
			updateOriginal.call(jQuery(".inputStatement")[0]);
		}
		else if (lastInput != null && !jQuery(lastInput).is(":disabled")) {
			jQuery(lastInput).val(jQuery(lastInput).val() + beta);
			updateOriginal.call(lastInput);
		}
	}
}

function addUpperQTips (){
	jQuery(this).qtip({
		content : {
			text : jQuery("#" + this.id.replace("Icon", ""))
		},
		position : {
			my : "bottom right",
			target : "top left"
		},
		style : {
			width : "600px"
		},
		hide : {
			fixed : true
		}
	});
}

function isSpecialVal (str){
	if (str[0] != "<")
		return false;
	
	for (let i = 0; i < SpecialValues.length; i++){
		if (str === "<" + SpecialValues[i] + ">"){
			return true;
		}
	}
	return false;
}

function convertToOriginal (text, type){
	
	if(!text || isSpecialVal(text))
		return "";
	
	text = text.trim();
	
	if(type){
		var charList = parserType.parse(text);
	}
	else {
		var charList = parserRecord.parse(text);
	}
		
	result = "";
	for (var i = 0; i < charList.length; i++){
		var match = charList[i];
		if(match.startsWith("\\\\")){
			var entry = match.substring(2);
		}
		else if (/[A-Z]/.test(match[0])){
			var entry = Codepage[match[0].toLowerCase() + match.substring(1)];
			entry = entry[0].toUpperCase() + entry.substring(1);
		}
		else {
			var entry = Codepage[match];
		}
		if(entry){
			result += "<span style='position: relative;'>" + entry + "</span>";
		}
		else {
			if (match.trim().startsWith("<")){
				result += "<span style='color: green'>" + match.replace("<", "&lt;").replace(">", "&gt;") + "</span>";
			}
			else {
				result += "<span style='color: red'>" + match + "</span>";
			}
		}
	}
	return result;
}

function insertConcept (id, text){
	for (var i = 0; i < Concepts.length; i++){
		if(name.localeCompare(Concepts[i]["text"]) > 0){
			Concepts.splice(i - 1, 0, {"id" : id, "text" : text});
			break;
		}
	}
}

function va_matcher (params, data) {
    // Always return the object if there is nothing to compare
    if (jQuery.trim(params.term) === '') {
      return data;
    }

    var original = data.text.toUpperCase();
    var term = params.term.toUpperCase();

    // Check if the text contains the term
    if (original.indexOf(term) > -1) {
      return data;
    }

    // If it doesn't contain the term, don't return anything
    return null;
}

function addRowJS (index){
	var concepSelect = getField("conceptList", index);
	concepSelect.select2(select2Data);
	
	jQuery("#inputRow" + index + " .helpIcon").each(addUpperQTips);
	
	var inputField = getField("inputStatement", index);
	try {
		content = convertToOriginal(inputField.val(), getField("classification", index).val() != "B");
	}
	catch (e){
		content = "<span style='color: red'>" + Translations.NOT_VALID + "</span>";
	}
	inputField.next().html(content);

	if(index > 0){
		getField("remover", index).click(function (){
			var element = jQuery(this).closest("tr");
			element.find(".conceptList").select2("destroy");
			element.remove();
			State.Index--;
			reindexRows();
			fixTdWidths();
		});
	}
}

function fixTdWidths (){
	var widthNumber = 0;
	var authorWidth = 0;
	var deleteWidth = 0;
	for (var i = 0; i <= State.Index; i++){
		widthNumber = Math.max(widthNumber, getField("spanNumber", i).width());
		authorWidth = Math.max(authorWidth, getField("authorSpan", i).width());
		deleteWidth = Math.max(deleteWidth, getField("deleteSpan", i).width());
	}
	
	var classWidth = getField("classification", 0).width() + 5;
	var totalWidth = jQuery(window).width() - 36 - 10 - 12 - 16 - 16;
	var sum = widthNumber + classWidth + authorWidth + deleteWidth;
	
	jQuery("#inputTable tr td:nth-child(1)").css("width", widthNumber);
	jQuery("#inputTable tr td:nth-child(2)").css("width", (totalWidth - sum) / 2);
	jQuery("#inputTable tr td:nth-child(3)").css("width", classWidth);
	jQuery("#inputTable tr td:nth-child(4)").css("width", (totalWidth - sum) / 2);
	jQuery("#inputTable tr td:nth-child(5)").css("width", authorWidth);
	jQuery("#inputTable tr td:nth-child(6)").css("width", deleteWidth);
}

var State = {
	Id_Stimulus : 0,
	Id_Informant : 0,
	Index : 0
}

function reindexRows (){
	var index = 0;
	jQuery("#inputTable tr").each(function (){
		jQuery(this).find("td:first span").html((index + 1) + ".)");
		jQuery(this).prop("id", "inputRow" + index);
		jQuery(this).data("index", index);
		index++;
	});
}

function layout(){
	if (!jQuery(document.body).hasClass('folded')){
		jQuery(document.body).addClass('folded');
	}
	
	var inputHeight = jQuery("#enterTranscription").outerHeight();
	var headerHeight = jQuery("#wpadminbar").outerHeight();
	var documentHeight = jQuery(document).outerHeight();
	var iFrameHeight = documentHeight - inputHeight - headerHeight;
	jQuery("#iframeScanDiv").css("height", iFrameHeight + "px");
	jQuery("#iframeCodepageDiv").css("height", iFrameHeight + "px");
}

function atlasChanged(){
	jQuery("#mapSelectionDiv").css("display", "none");
	jQuery("#mapSelection").val("").trigger("change");
	
	if(this.value == -1)
		return;
	
	jQuery("#mapSelectionDiv").css("display", "inline");
		
	var data = create_ajax_data({
		"query" : "update_grammar",
		"atlas" : this.value
	});
	
	jQuery.post(ajaxurl, data, function (response){
		var res = JSON.parse(response);
		parserRecord = peg.generate(res[0]);
		parserType = peg.generate(res[1]);
	});
}

function mapChanged (value){
	if(value == null)
		value = "";
	
	var pos = value.indexOf('|');
	jQuery("#mapSelection").next().css("width", "30%");
	var selElement = jQuery("#mapSelection").next().find(".select2-selection__rendered");
	if(pos != -1){
		addTTLock (value.substring(0, pos), function (response){
			if(response == "success"){
				State.Id_Stimulus = value.substring(0, pos);
				var map = value.substring(pos + 1);
				changeIFrame("iframeScan", url + map.substring(0, map.indexOf("#")) + "/" + map.replace('#', '%23'));
				selElement.css("background-color", "#80FF80")
				ajax_info();
			}
			else {
				alert(Translations.LOCKED);
				jQuery("#mapSelection").val("").trigger("change");
			}
		});
	}
	else {
		changeIFrame("iframeScan", Rules_File);
		selElement.css("background-color", "#fe7266")
		jQuery("#input_fields").addClass("hidden_c");
		jQuery("#informant_info").addClass("hidden_c");
		jQuery("#error").html("").addClass("hidden_coll");
		State.Id_Stimulus = 0;
		State.Id_Informant = 0;
		removeTTLock (false);
	}
}

function changeIFrame (id, src){
	var ifr = jQuery("#" + id);
	if(ifr.prop("src") == src)
		return;
	ifr.replaceWith("<iframe id='" + id + "' src='" + src + "'></iframe>");
}

function ajax_info (){

	if(State.Id_Stimulus == "")
		return;
	
	jQuery("#input_fields").addClass("hidden_c");
	jQuery("#error").html("").addClass("hidden_coll");
	jQuery("#informant_info").addClass("hidden_c");
	
	var mapVal = jQuery("#mapSelection").val();
	
	var data = create_ajax_data({
		"query" : "update_informant",
		"mode" : jQuery("#mode").val(),
		"region": getRegion(jQuery("#region").val()),
		"filters" : getFilters(),
		"id_stimulus" : mapVal.substring(0, mapVal.indexOf("|"))
	});
	
	jQuery.post(ajaxurl, data, function (response) {
		updateFields(response);
	});
}

function getRegion (value){
	if(value == ""){
		return "%";
	}
	return value;
}

function updateFields (info){
	var errorDiv = jQuery("#error");
	var mode = jQuery("#mode").val();
	
	try {
		var obj = JSON.parse(info);
		jQuery("#informant_info").html("<span class='informant_fields'>" + obj[0].Source + " " + obj[0].Map_Number + "_" + obj[0].Sub_Number + " - " + obj[0].Stimulus 
				+ "</span> - Informant_Nr <span class='informant_fields'>" + obj[0].Informant_Number + "</span> (" + obj[0].Place_Name + ")");
		
		State.Id_Informant = obj[0].Id_Informant;
		
		jQuery(".conceptList").select2("destroy");
		jQuery("#inputTable").empty();
		
		State.Index = obj.length - 1;
		for (var i = 0; i < obj.length; i++){
			jQuery("#inputTable").append(obj[i].html.replace(/\\/g, ""));
			var inputField = getField("inputStatement", i);
			if(mode == 'first'){
				inputField.val("");
			}
			else {
				inputField.val(obj[i].Attestation);
				getField("classification", i).val(obj[i].Classification);
			}
			inputField.prop("disabled", obj[i].readonly);
			getField("classification", i).prop("disabled", obj[i].readonly);
			getField("conceptList", i).prop("disabled", obj[i].readonly).trigger("change");

			addRowJS(i);
			
			getField("conceptList", i).val(obj[i].Concept_Ids).trigger("change");
		}
		fixTdWidths();
		
		jQuery("#input_fields").removeClass("hidden_c");
		jQuery("#insertAttestation").val(jQuery("#mode").val() == "first"? Translations.INSERT: Translations.UPDATE);
		jQuery("#informant_info").removeClass("hidden_c");
		getField("inputStatement", 0).focus();
	}
	catch (s){
		jQuery("#input_fields").addClass("hidden_c");
		jQuery("#informant_info").addClass("hidden_c");
		State.Id_Informant = 0;
		
		if(s instanceof SyntaxError){
			errorDiv.html(info);
		}
		else {
			errorDiv.text(s);
		}
		errorDiv.removeClass("hidden_coll");
	}	
}

function getField (className, index){
	return jQuery("#inputRow" + index + " ." + className);
}

function writeAttestation (content){
	
	var data = [];
	
	if (content !== undefined){ //Special cases
		//TODO warning if there is content
		data[0] = {"attestation" : content, "classification" : jQuery(".classification:first option:first").val(), "concepts" : [], "id_attestation" : jQuery("#inputTable tr").first().data("id")};
	}
	else {
		try {
			var index = 0;
			jQuery("#inputTable tr").each(function (){ //Normal attestation(s)
				index++;
				
				var text = jQuery(this).find(".inputStatement").val();
				var classif = jQuery(this).find(".classification").val();
				try {
					var original = convertToOriginal(text, classif != "B");
				}
				catch (e){
					throw Translations.INVALID_RECORD.replace("%", index);
				}
				
				var cids = jQuery(this).find(".conceptList").val();
				
				if(!text){
					throw Translations.NO_INPUT.replace("%", index);
				}
				else if (cids == null && original != "" /*Empty string used for problems or other special values*/){
					throw Translations.NO_CONCEPTS.replace("%", index)
				}
				
				data.push({"attestation" : text, "classification" : classif, "concepts" : cids, "id_attestation" : jQuery(this).data("id")});
			});
		}
		catch (e) {
			alert(e);
			return;
		}
	}
	
	jQuery("#input_fields").addClass("hidden_c");
	jQuery("#error").html("").addClass("hidden_coll");
	
	var ajax_data = create_ajax_data({
			"query" : "update_transcription",
			"id_stimulus": State.Id_Stimulus,
			"id_informant": State.Id_Informant,
			"mode" : jQuery("#mode").val(),
			"region" : getRegion(jQuery("#region").val()),
			"filters" : getFilters(),
			"data": data
	});
	
	jQuery("#inputTable").css("background", "green");
	
	jQuery.post(ajaxurl, ajax_data, function (response) {
		jQuery("#inputTable").css("background", "");
		updateFields(response);
	});
}

function getFilters (){
	var res = [];
	jQuery("#informant_filter_screen input:checked").each(function (){
		res.push([jQuery(this).data("col"), jQuery(this).data("val"), jQuery(this).data("type")]);
	});
	
	return res;
}

function create_ajax_data (obj){
	return Object.assign({}, AjaxData, obj);
}