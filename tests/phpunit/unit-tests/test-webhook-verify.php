<?php

use MailHawk\Utils\Signature_Verifier;

class Test_Webhook_Verify extends WP_UnitTestCase {

	public function test() {

		$dkim      = "v=DKIM1; t=s; h=sha256; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDmqBYZsLSSkZFscXdIyCJbvzyO1D4ODyo87KYfwNqBMw5h1FWq9dTSRpRNdyphnljWCwOZ3+ajkN3m3JIY2mvIFyI1DV9kDt51F9Tnyv/TaCotcrHmkic5yS6Lk3omr+j7zoUglFJcnqdmtwNW8M6sv93BXR5lVsJix5vpq3GWywIDAQAB;";
		$signature = "4b6TnGPu0i4f/PtYhVJr6yFydph9sKtCCYmpKHjHdvbHwKhXyIOyeKvBwmgXji+SNwQT/ADXxgM1LgFum0BbP6F7YKQDdrPZwKTF62Xto6KKlDTXETz4PNC5EP3S18Qj1IaPg/D5oxNejAABfiqOtD4zYQqrDxGl6ZckTPbhagU=";
		$payload   = '{"event":"MessageSent","timestamp":1584646373.302839,"payload":{"message":{"id":18,"token":"NlhCw9TyLQbv","direction":"outgoing","message_id":"1fd2dfb1-202e-4bed-b8ba-f371aa42406a@rp.mta01.mailhawk.io","to":"adrian.methoss@gmail.com","from":"test@mta01.mailhawk.io","subject":"Test Message at March 19, 2020 19:32","timestamp":1584646372.7045782,"spam_status":"NotChecked","tag":null},"status":"Sent","details":"Message for adrian.methoss@gmail.com accepted by gmail-smtp-in.l.google.com (2607:f8b0:4001:c07::1a)","output":"250 2.0.0 OK  1584646373 s67si2763608ill.8 - gsmtp\n","sent_with_ssl":false,"timestamp":1584646373.285709,"time":0.26},"uuid":"d5336f05-b350-4cf9-8783-cc888d8f6069"}';

		$verify = new Signature_Verifier();

		$this->assertEquals( 1, $verify->verify( $payload, $signature, $dkim ) );

	}

}