# TYPO3 extension paypal2commerce

## What is it?

This Extension uses the paypal-express checkout and it is based on the PayPal PHP SDK Samples.
(Name-Value Pair Api: https://www.paypal.com/en_US/ebook/PP_NVPAPI_DeveloperGuide/index.html)

## Documentation
Documentation is available at:

* http://wiki.typo3.org/index.php/Extensions/paypal2commerce/manual
* http://wiki.typo3.org/index.php/Extensions/paypal2commerce/De:manual

You add via Backend an paypal-Payment-Article and have to set the database-Field "classname" to "paypal"
(you find it in the table  tx_commerce_articles) AND you have to set an price (maybe 0 Euro).

You should test via https://developer.paypal.com

You will send to Paypal in the last step. There you can login and pay. 
I do not use the adress from paypal at this moment. I am not sure if it makes sense.

## Extension requirements
This extension uses CURL, so make sure you have installes PHP CURL. If you are unsure if CURL is installed on your
server, please use the included file test_curl.php to verify.

Please configure in typoscript:
config.baseURL = http://yourserver.com/

# Support and updates
The extension is hosted on GitHub. Please report feedback, bugs and changerequest directly at https://github.com/derhansen/paypal2commerce

