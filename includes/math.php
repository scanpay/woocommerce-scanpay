<?php

defined('ABSPATH') || exit();

/*
    wc_scanpay_dighomogenize(): turn 123.4 and 56.78 into 12340 and 05678
*/
function wc_scanpay_dighomogenize($a, $b)
{
    $h = array();
    $h["as"] = ($a[0] == '-');
    $h["bs"] = ($b[0] == '-');
    $aa = explode(".", ($h["as"] ? substr($a, 1) : $a) . "."); /* guarantee 2 elems */
    $bb = explode(".", ($h["bs"] ? substr($b, 1) : $b) . ".");
    $h["il"] = max(strlen($aa[0]), strlen($bb[0]));
    $h["fl"] = max(strlen($aa[1]), strlen($bb[1]));
    $h["a"] = str_pad($aa[0], $h["il"], "0", STR_PAD_LEFT) . str_pad($aa[1], $h["fl"], "0");
    $h["b"] = str_pad($bb[0], $h["il"], "0", STR_PAD_LEFT) . str_pad($bb[1], $h["fl"], "0");
    return $h;
}

/*
    wc_scanpay_digformat() turn 012340 into 123.40 or 12300 into 123
*/
function wc_scanpay_digformat($sign, $s, $fl)
{
    $il = strlen($s) - $fl;
    $s = ltrim(substr($s, 0, $il), "0") . "." . substr($s, $il);
    if ($s == "" || $s[0] == '.') {
        $s = "0" . $s;
    }
    for ($d = strlen($s) - 1; $d > 0 && $s[$d] == '0'; $d--);
    return ($sign ? "-" : "") . (($s[$d] == '.') ? substr($s, 0, $d) : $s);
}

/*
    wc_scanpay_digadd()
*/
function wc_scanpay_digadd($a, $b)
{
    for ($s = "", $rem = 0, $i = strlen($a) - 1; $i >= 0; $i--) {
        $r = intval($a[$i]) + intval($b[$i]) + $rem;
        if ($r >= 10) {
            $r -= 10;
            $rem = 1;
        } else {
            $rem = 0;
        }
        $s[$i] = strval($r);
    }
    return ($rem > 0) ? strval($rem) . $s : $s;
}

/*
    wc_scanpay_digsub()
*/
function wc_scanpay_digsub($a, $b)
{
    for ($s = "", $rem = 0, $i = strlen($a) - 1; $i >= 0; $i--) {
        if ($a[$i] < $b[$i] + $rem) {
            $s[$i] = 10 + $a[$i] - ($b[$i] + $rem);
            $rem = 1;
        } else {
            $s[$i] = $a[$i] - ($b[$i] + $rem);
            $rem = 0;
        }
    }
    return $s;
}

/*
    wc_scanpay_addmoney()
*/
function wc_scanpay_addmoney($a, $b)
{
    $h = wc_scanpay_dighomogenize($a, $b);
    // sign magic to avoid subtracting a larger number from a smaller one
    if ($h["as"] != $h["bs"]) {
        if (strcmp($h["a"], $h["b"]) < 0) {
            $s = wc_scanpay_digsub($h["b"], $h["a"]);
            $h["as"] = !$h["as"];
        } else {
            $s = wc_scanpay_digsub($h["a"], $h["b"]);
        }
    } else {
        $s = wc_scanpay_digadd($h["a"], $h["b"]);
    }
    return wc_scanpay_digformat($h["as"], $s, $h["fl"]);
}

/*
    wc_scanpay_submoney(): a - b ≡ a + (-b)
*/
function wc_scanpay_submoney($a, $b)
{
    return wc_scanpay_addmoney($a, ($b[0] == '-') ? substr($b, 1) : ("-" . $b));
}

/*
    wc_scanpay_cmpmoney()
*/
function wc_scanpay_cmpmoney($a, $b)
{
    $h = wc_scanpay_dighomogenize($a, $b);
    if ($h["as"] && $h["bs"]) {
        return strcmp($h["b"], $h["a"]);
    }
    if ($h["as"]) {
        return -1;
    }
    if ($h["bs"]) {
        return 1;
    }
    return strcmp($h["a"], $h["b"]);
}

/*
    wc_scanpay_roundmoney() (not in use)
*/
function wc_scanpay_roundmoney($a, $n)
{
    $h = wc_scanpay_dighomogenize($a, "0." . str_repeat("0", $n) . "5");
    $s = wc_scanpay_digadd($h["a"], $h["b"]);
    return wc_scanpay_digformat($h["as"], substr($s, 0, strlen($s) - $h["fl"] + $n), $n);
}
