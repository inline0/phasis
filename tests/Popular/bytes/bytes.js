"use strict";var Bytes=(()=>{var g=(r,e)=>()=>(e||r((e={exports:{}}).exports,e),e.exports);var w=g((M,n)=>{n.exports=v;n.exports.format=b;n.exports.parse=l;var x=/\B(?=(\d{3})+(?!\d))/g,B=/(?:\.0*|(\.[^0]+)0+)$/,i={b:1,kb:1024,mb:1<<20,gb:1<<30,tb:Math.pow(1024,4),pb:Math.pow(1024,5)},h=/^((-|\+)?(\d+(?:\.\d+)?)) *(kb|mb|gb|tb|pb)$/i;function v(r,e){return typeof r=="string"?l(r):typeof r=="number"?b(r,e):null}function b(r,e){if(!Number.isFinite(r))return null;var a=Math.abs(r),f=e&&e.thousandsSeparator||"",m=e&&e.unitSeparator||"",p=e&&e.decimalPlaces!==void 0?e.decimalPlaces:2,o=!!(e&&e.fixedDecimals),t=e&&e.unit||"";(!t||!i[t.toLowerCase()])&&(a>=i.pb?t="PB":a>=i.tb?t="TB":a>=i.gb?t="GB":a>=i.mb?t="MB":a>=i.kb?t="KB":t="B");var c=r/i[t.toLowerCase()],s=c.toFixed(p);return o||(s=s.replace(B,"$1")),f&&(s=s.split(".").map(function(u,d){return d===0?u.replace(x,f):u}).join(".")),s+m+t}function l(r){if(typeof r=="number"&&!isNaN(r))return r;if(typeof r!="string")return null;var e=h.exec(r),a,f="b";return e?(a=parseFloat(e[1]),f=e[4].toLowerCase()):(a=parseInt(r,10),f="b"),isNaN(a)?null:Math.floor(i[f]*a)}});return w();})();
/*! Bundled license information:

bytes/index.js:
  (*!
   * bytes
   * Copyright(c) 2012-2014 TJ Holowaychuk
   * Copyright(c) 2015 Jed Watson
   * MIT Licensed
   *)
*/
