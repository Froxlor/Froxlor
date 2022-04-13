// load bootstrap
import 'bootstrap';
import 'chart.js/dist/chart';

// load jquery
global.$ = require('jquery');

$(document).ready(function () {
	window.$theme = 'Froxlor';
});

// Load components
require('./components/search')
require('./components/newsfeed')
require('./components/updatecheck')
require('./components/customer')
require('./components/ipsandports')
require('./components/domains')
require('./components/configfiles')