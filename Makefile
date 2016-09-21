SRCS=\
	includes/Gateway.php \
	includes/Money.php \
	includes/ScanpayClient.php \
	woocommerce-scanpay.php

LANGS=en_GB

i18n: $(LANGS:%=languages/woocommerce-scanpay-%.po)

%.po: $(SRCS)
	touch $@
	xgettext -k__ -j -o $@ $^
