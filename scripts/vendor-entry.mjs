import jQuery from 'jquery';
window.jQuery = window.$ = jQuery;

import ClipboardJS from 'clipboard';
window.ClipboardJS = ClipboardJS;

import JSEncrypt from 'jsencrypt';
window.JSEncrypt = JSEncrypt;

import SparkMD5 from 'spark-md5';
window.SparkMD5 = SparkMD5;

import moment from 'moment';
import 'moment-timezone/builds/moment-timezone-with-data-10-year-range.js';
window.moment = moment;

// jQuery plugins — attach to $.fn via the bundled jQuery.
// Selectize must also be exposed as a global: the app's selectize-plugins.min.js
// calls Selectize.define() to register custom plugins.
import 'magnific-popup';
import Selectize from '@selectize/selectize';
window.Selectize = Selectize;
