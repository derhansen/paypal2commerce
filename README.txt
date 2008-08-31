Documentation will be found there soon:

http://wiki.typo3.org/index.php/Extensions/paypal2commerce/manual

http://wiki.typo3.org/index.php/Extensions/paypal2commerce/De:manual


This Extension uses the paypal-express checkout and it is based on the PayPal PHP SDK Samples.
(Name-Value Pair Api: https://www.paypal.com/en_US/ebook/PP_NVPAPI_DeveloperGuide/index.html)

It uses PHP with CURL extension enabled.

You add via Backend an paypal-Payment-Article and have to set the database-Field "classname" to "paypal"
(you find it in the table  tx_commerce_articles) AND you have to set an price (maybe 0 Euro).

You should test via https://developer.paypal.com

You will send to Paypal in the last step. There you can login and pay. 
I do not use the adress from paypal at this moment. I am not sure if it makes sense.

Please configure in typoscript:
config.baseURL = http://yourserver.com/


There is an php-file test_curl.php.
Please check that before sending me an mail.


Questions? Comments?
Please send an mail: Martin Holtz, typo3@martinholtz.de
(Gerne auch auf deutsch;)


TODOS:
- Testing
- correct error heandling
