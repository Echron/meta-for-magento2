(function(){function r(e,n,t){function o(i,f){if(!n[i]){if(!e[i]){var c="function"==typeof require&&require;if(!f&&c)return c(i,!0);if(u)return u(i,!0);var a=new Error("Cannot find module '"+i+"'");throw a.code="MODULE_NOT_FOUND",a}var p=n[i]={exports:{}};e[i][0].call(p.exports,function(r){var n=e[i][1][r];return o(n||r)},p,p.exports,r,e,n,t)}return n[i].exports}for(var u="function"==typeof require&&require,i=0;i<t.length;i++)o(t[i]);return o}return r})()({1:[function(require,module,exports){
var jQuery = (function (jQuery) {
    if (jQuery && typeof jQuery === 'function') {
        return jQuery;
    } else {
        console.error('window.jQuery is not valid or loaded, please check your magento 2 installation!');
        // if jQuery is not there, we return a dummy jQuery obejct with ajax,
        // so it will not break our following code
        return {
            ajax: function () {
            }
        };
    }
})(window.jQuery);
var ajaxify = function (url) {
    return url + '?isAjax=true&storeId=' + window.facebookBusinessExtensionConfig.storeId;
};

var ajaxParam = function (params) {
    if (window.FORM_KEY) {
        params.form_key = window.FORM_KEY;
    }
    return params;
};

function consoleLog(message) {
    if (window.facebookBusinessExtensionConfig.debug) {
        console.log(message);
    }
}

function parseURL(url) {
    var parser = document.createElement('a');
    parser.href = url;
    return parser;
}

function urlFromSameDomain(url1, url2) {
    var u1 = parseURL(url1);
    var u2 = parseURL(url2);
    var u1host = u1.host.replace('web.', 'www.');
    var u2host = u2.host.replace('web.', 'www.');
    return u1.protocol === u2.protocol && u1host === u2host;
}

function deleteFBAssets() {
    var _this = this;
    jQuery.ajax({
        type: 'delete',
        url: ajaxify(window.facebookBusinessExtensionConfig.deleteConfigKeys),
        data: ajaxParam({
            storeId: window.facebookBusinessExtensionConfig.storeId,
        }),
        success: function onSuccess(data, _textStatus, _jqXHR) {
            let msg = '';
            if (data.success) {
                msg = data.message;
            } else {
                msg = data.error_message;
            }
            cleanConfigCache();
            consoleLog(msg);
            consoleLog("Successfully uninstalled FBE");
            window.location.reload();
        },
        error: function () {
            console.error('There was a problem deleting the connection, Please try again.');
        }
    });
}

function cleanConfigCache() {
    jQuery.ajax({
        type: 'post',
        url: ajaxify(window.facebookBusinessExtensionConfig.cleanConfigCacheUrl),
        data: ajaxParam({}),
        success: function onSuccess(data, _textStatus, _jqXHR) {
            if (data.success) {
                consoleLog('Config cache successfully cleaned');
            }
        },
        error: function () {
            console.error('There was a problem cleaning config cache');
        }
    });
}

function handleCommerceExtensionDeletion(data) {
    if (data) {
        var responseObj = data;
        consoleLog("Response from fb login:");
        consoleLog(responseObj);
        var success = responseObj.success;
        if (success) {
            let action = responseObj.action;
            if (action != null && action === 'delete') {
                // Delete asset ids stored in db instance.
                deleteFBAssets();
            } else {
                consoleLog("No response received after setup");
            }
        }
    }
}

function listenForCommerceExtensionDeletion(event) {
    var origin = event.origin || event.originalEvent.origin;
    var commerceExtensionOrigin = document.getElementById("commerce-extension-iframe").src;
    if (urlFromSameDomain(origin, new URL(commerceExtensionOrigin).origin)) {
        // Make ajax calls to store data from fblogin and fb installs
        consoleLog("Message from Meta Commerce Extension ");
        handleCommerceExtensionDeletion(event.data); // Changed this line
    }
}

(function main() {
    window.addEventListener('message', listenForCommerceExtensionDeletion);
})();

},{}]},{},[1]);