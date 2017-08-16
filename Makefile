SRCS=\
	includes/Gateway.php\
	includes/ScanpayClient.php\
	woocommerce-scanpay.php\

LANGS=\
	en_US\

TMPL=languages/woocommerce-scanpay.pot

POBJS=$(LANGS:%=languages/woocommerce-scanpay-%.po)
MOBJS=$(POBJS:%.po=%.mo)

all: mo

po: $(POBJS)

mo: $(MOBJS)

$(TMPL): $(SRCS)
	@mkdir -p $(dir $@)
	@touch $@
	xgettext -k__ -j -o $@ $^
	@sed -i -r -e 's%("Content-Type: text/plain; charset=)(.*)(\\n")%\1UTF-8\3%' $@

$(POBJS): $(TMPL)
	test -f $@ || msginit --no-translator --output=$@ --input=$< --locale=$(@:languages/woocommerce-scanpay-%.po=%).UTF-8
	test ! -f $@ || msgmerge -U $@ $<

#	if [ -a $@ ] ; \
#		msgmerge -U $@ $< ; \
#	then \
#	msginit --no-translator --output=$@ --input=$< --locale=$(@:languages/woocommerce-scanpay-%.po=%).UTF-8 ; \
#	fi;

%.mo: %.po
	msgfmt -o $@ $<

clean:
	rm -f languages/*.mo

.PHONY: all po mo clean
