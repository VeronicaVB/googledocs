<?php

/* PHP doesn’t support the Base64URL standard, but you can use built-in functions to normalize values.
 * Encode data to Base64URL
 * @param string $data
 * @return boolean|string
 * Source : https://base64.guru/developers/php/examples/base64url
 *
 *
 */
function base64url_encode($data) {
  return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
  return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
}
