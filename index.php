<?php

require "vendor/autoload.php";

use Simsoft\HttpClient\HttpClient;




 $response = (new HttpClient())
     ->withBaseUri('https://eu.api.dowjones.com/risk-entity-screening-cases/92d31570-ebcb-4701-82a2-7cb1b07f1b1d/matches?filter[has_alerts]=true&filter[is_match_valid]=true')
     //->withMethod('POST')
     ->withHeaders(['Authorization' => 'Bearer eyJhbGciOiJSUzI1NiIsImtpZCI6IjJEN0IwQTFERkJCNzlDRDFBQjM4NzNCMTcyODMyRjkxMENEQkRBREIiLCJ0eXAiOiJKV1QifQ.eyJwaWIiOnsiYXBjIjoiUk5DIiwiY3RjIjoiUCIsIm1hc3Rlcl9hcHBfaWQiOiJCODEzc0dqUnlhSHAzUlZYczJjbkNkeGVxREM2dzZiQUdaTDNDYWx6Iiwic2VydmljZV9hY2NvdW50X2lkIjoiOUNUTzAwMDcwMCIsImVuY3J5cHRlZF90b2tlbiI6IlMwMll0SnEzR2JqWlRxbk5wVW1OOUl2TjlBbUpjYmFQVFlxT1RRc09URWMzSGJtWlRyVFJWSldTVU5GWHFGRFFxelZUYlI1VHFGVlZFNmMifSwiaXNzIjoiaHR0cHM6Ly9ldS5hY2NvdW50cy5kb3dqb25lcy5jb20vb2F1dGgyL3YyIiwic3ViIjoiOTI1MTI4YTQ3NmVmNDhlNTU3ZDMwMmNiZDBmMmE1NjMiLCJhdWQiOiJCODEzc0dqUnlhSHAzUlZYczJjbkNkeGVxREM2dzZiQUdaTDNDYWx6IiwiYXpwIjoiQjgxM3NHalJ5YUhwM1JWWHMyY25DZHhlcURDNnc2YkFHWkwzQ2FseiIsImlhdCI6MTc1MDYyOTkzNiwiZXhwIjoxNzUwNjMzNTM2fQ.vrkVmqj4mhG_wEhFSfFFAdvgaqIHf9q5m-7Zw2wN3t_C4n5eg96FK1XKFv0JAAA49nHLP0UPWLIAVEKohpUrxmPGKaVz79azWcnXnJgwYI0hSM7w0ejqq-CAZFRNvKXfzzb3qrgRk9D-PltNzjuOZO3_4aF67ZLuIVizl54dZI8ATr3CKUtMxSsiEtzUA571DejPhDx240aVKUZ_Etj7fCrdYNQs3tVfq1V8GcjtMpL1pwFPFVBNcFftKzWJuWuA7_yD7TMtCTx2ZsLsLyym_aR_NEGk2tdfPn0vo81em04VN7nlI-2JDoE_WVS8fxgxWepvooW1ZQZOjEqHnhtOOQ'])
     //->urlEncoded(['key' => 'value'])

     ->get();

if ($response->ok()) { // status code 200.

} else {
    var_dump($response->getAttribute('errors.0.code'));
}
