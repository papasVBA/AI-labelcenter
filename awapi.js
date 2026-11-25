//univerzal pro reseni requestu

function makeFORMRequest(method, target, data, callBackFn, errorFn = errRequest, timeout, spintext="stahuji data", brunspin=true) {
    var xmlhttp=new XMLHttpRequest();
    if (brunspin === true){
        runspin(spintext);
    } else {
        //silent request
    }
    
    xmlhttp.ontimeout = (e) => {
        xmlhttp.abort();
        errorFn();
   }

   xmlhttp.onreadystatechange=function() {
    if (this.readyState==4 && this.status==200) {
        stopspin();
        callBackFn(this.responseText);
    } else {
        // ---
    }
}

xmlhttp.open(method, target, true);
xmlhttp.timeout = timeout;
/*
https://developer.mozilla.org/en-US/docs/Web/API/XMLHttpRequest_API/Using_FormData_Objects
Warning: When using FormData to submit POST requests using XMLHttpRequest or the Fetch API
 with the multipart/form-data content type 
 (e.g., when uploading files and blobs to the server), 
 do not explicitly set the Content-Type header on the request. !!!!
 Doing so will prevent the browser from being able to set the Content-Type header 
 with the boundary expression it will use to delimit form fields in the request body.
*/

xmlhttp.send(data);
}





function makeRequest(method, target, data, callBackFn, errorFn = errRequest, timeout, spintext="stahuji data", brunspin=true) {
    var xmlhttp=new XMLHttpRequest();
    if (brunspin === true){
        runspin(spintext);
    } else {
        //silent request
    }
    
    xmlhttp.ontimeout = (e) => {
        xmlhttp.abort();
        errRequest();
   }

   xmlhttp.onreadystatechange=function() {
    if (this.readyState==4 && this.status==200) {
        stopspin();
        callBackFn(this.responseText);
    } else {
        // ---
    }
}

xmlhttp.open(method, target, true);
xmlhttp.timeout = timeout;
xmlhttp.setRequestHeader('Content-Type','application/json');
xmlhttp.send(data);
//console.log('sended Request');
}



function errRequest(){
    stopspin();
    console.log('XHR-timeout');
    //alert("request error");
}



