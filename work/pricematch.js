var Pricematch = Class.create();
Pricematch.prototype = {
    options: {},
    initialize: function() {},
    setUrl: function(url) {
        this.options.url = url;
        return this;
    },
    getUrl: function() {
        return (this.options.url) ? this.options.url : null;
    },
    resetUrl: function() {
        this.options.url = null;
        return this;
    },
    setCompetitorId: function(id) {
        this.options.competitorId = id;
        return this;
    },
    openPopup: function() {
        var win = Dialog.info(null, {
            closable: true,
            resizable: false,
            dragable: true,
            className: "magento",
            windowClassName: 'popup-window competitor-price-match',
            title: "Competitor Price Match",
            top: 50,
            width: window.screen.width - 300,
            zIndex: 1000,
            recenterAuto: true,
            hideEffect: Element.hide,
            showEffect: Element.show,
            id: 'widget_window'
        });
        return this;
    },
    priceMatchList: function(canOpenPopup = true) {
        var url = this.options.url;
        if (this.options.competitorId) {
            url = url + 'competitor_id/' + this.options.competitorId + '/';
        }
        if (canOpenPopup) {
            this.openPopup();
        }
        new Ajax.Updater('modal_dialog_message', url, {
            evalScripts: true
        });
        this.resetUrl();
        return this;
    },
    changeProductPrice: function(evt = null) {
        if (evt && !validateCost(evt)) {
            return false;
        }
        var totalPrice = 0;
        var canShow = true;
        jQuery('#repricer tr').each(function() {
            var trObj = this;
            jQuery(trObj).find('.competitor-price-textbox').each(function() {
                var inputObj = this;
                totalPrice += (isNaN(parseFloat(jQuery(inputObj).val()))) ? 0 : parseFloat(jQuery(inputObj).val());
                jQuery(trObj).find('button').each(function() {
                    if (jQuery(this).attr('class') != "competitor-onesb-edit") {
                        jQuery(this).css('background', '');
                        if (parseFloat(jQuery(inputObj).val()) > 0) {
                            jQuery(this).removeClass('add-btn').addClass('update-btn').html('Update');
                        } else {
                            jQuery(this).removeClass('update-btn').addClass('add-btn').html('Add');
                            canShow = false;
                        }
                    }
                });
            });
        });
        jQuery(".competitor-total-price").html(totalPrice.toFixed(2));
        return true;
    },
    saveCompetitorProduct: function(obj) {
        (obj.className == 'update-btn') ? jQuery(obj).css('background', '#eaa95b'): jQuery(obj).css('background', '#71a1ce');
        return this;
    },
    setMsgObj: function(id) {
        this.options.msgObj = jQuery("#" + id);
        return this;
    },
    getMsgObj: function() {
        return (this.options.msgObj) ? this.options.msgObj : null;
    },
    getSuccessMsg: function(msg) {
        return (msg) ? '<ul class="messages"><li class="success-msg"><ul><li><span>' + msg + '</span></li></li></ul>' : null;
    },
    getErrorMsg: function(msg) {
        return (msg) ? '<ul class="messages"><li class="error-msg"><ul><li><span>' + msg + '</span></li></li></ul>' : null;
    },
    setAjaxParameter: function(parameters) {
        this.options.parameters = parameters;
        return this;
    },
    getAjaxParameter: function(parameters) {
        if (this.options.parameters) {
            return this.options.parameters;
        }
        return null;
    },
    onSuccessAction: function() {
        return this;
    },
    onCompleteAction: function() {
        return this;
    },
    ajaxReuestMethod: function() {
        var _this = this;
        var url = this.options.url;
        this.resetUrl();
        new Ajax.Request(url, {
            method: 'post',
            parameters: _this.getAjaxParameter(),
            onSuccess: function(response) {
                response = JSON.parse(response.responseText);
                _this.onSuccessAction(response);
            },
            onComplete: function() {
                setTimeout(function() {
                    _this.onCompleteAction();
                }, 30000);
            }
        });
        this.setAjaxParameter(null);
        return this;
    },
    saveCompetitorProductDetails: function() {
        var customForm = new varienForm('competitor-product-details');
        if (!customForm.validate()) {
            if (jQuery('.competitor-url-missing').is(":hidden")) {
                jQuery('.price-match-content').addClass('missing-url-overlay');
                jQuery('.competitor-url-missing').show();
            }
            return;
        };
        this.onSuccessAction = function(response) {
            if (response.redirect) {
                this.setUrl(response.redirect);
                this.priceMatchList(false);
            }
            if (response.error) {
                this.getMsgObj().html(this.getErrorMsg(response.error)).show();
            }
        };
        this.onCompleteAction = function() {
            this.getMsgObj().hide();
        };
        this.setAjaxParameter(jQuery("#competitor-product-details").serialize());
        this.ajaxReuestMethod();
        return this;
    },
    createComparisonButton: function(url) {
        var btn = '<button type="button" class="competitor-generate-link" onclick="';
        btn += "pricematch.redirectComparisonLink('" + url + "');";
        btn += '"> Comparison link</button>';
        return btn;
    },
    generateOrSendLink: function() {
        this.onSuccessAction = function(response) {
            if (response.success) {
                if (response.url) {
                    jQuery('.generated-url').html(this.createComparisonButton(response.url));
                }
                if (response.message) {
                    this.getMsgObj().html(this.getSuccessMsg(response.message)).show();
                }
            } else {
                this.getMsgObj().html(this.getErrorMsg(response.message)).show();
            }
        }
        this.onCompleteAction = function() {
            this.getMsgObj().hide();
        }
        this.ajaxReuestMethod();
        return this;
    },
    redirectComparisonLink: function(url) {
        window.open(url, '_blank');
        return this;
    },
    editShippingMethod: function(obj) {
        var spanObj = jQuery(obj).parent();
        spanObj.hide();
        var obj = jQuery(obj).parent().parent();
        obj.find('.competitor-shipping-content').hide();
        obj.find('.competitor-shipping-edit').show();
        return this;
    },
    cancelShippingMethod: function (obj) {
        var obj = jQuery(obj).parent().parent().parent().parent();
        obj.find('.competitor-shipping-content').show();
        obj.find('span').show();
        obj.find('.competitor-shipping-edit').hide();
        return this;
    },
    saveCompetitorShipping: function(url) {
        var radioValue = jQuery("input[type='radio']:checked").val();
        if (radioValue == "payable") {
            var customForm = new varienForm('competitor-shipping');
            if (!customForm.validate()) {
                return;
            };
        }
        this.setUrl(url);
        this.onSuccessAction = function(response) {
            if (response.success) {
                this.setUrl(response.url);
                this.priceMatchList(false);
            } else {
                this.getMsgObj().addClass("not-available").html(response.message).show();
            }
        };
        this.onCompleteAction = function() {
            this.getMsgObj().hide();
        }
        this.setAjaxParameter(jQuery('#competitor-shipping').serialize());
        this.ajaxReuestMethod();
        return this;
    },
    editReturnPolicyOrReviewOrSummary: function(obj) {
        var _html = '';
        var tdObj = jQuery(obj).closest('td');

        tdObj.find('ul li').each(function() {
            var str = jQuery(this).text();
            _html += jQuery.trim(str.replace(/[\r\n]+/gm,'')) + '\n';
        });

        if (_html == '') {
            var divObj = jQuery(tdObj).clone().find('.return-policy-edit');
            divObj.find('span').remove();
            _html =  divObj.text();
        }

        tdObj.find('.return-policy-edit').hide();
        tdObj.find('.return-policy-save-cancel').html('<textarea class = "competitor-onesb-textarea">' + jQuery.trim(_html) + '</textarea><span><button class="competitor-onesb-submit" type="button" onclick="pricematch.setUrl(\''+this.getUrl()+'\').saveReturnPolicyOrReviewOrSummary(this);">Save</button><a onclick="pricematch.cancelReturnPolicyOrReviewOrSummary(this)">Cancel</a></span>').show();
        return this;
    },
    cancelReturnPolicyOrReviewOrSummary: function (obj) {
        var obj = jQuery(obj).parent().parent().parent();
        obj.find('.return-policy-edit').show();
        obj.find('.return-policy-save-cancel').hide();

    },
    saveReturnPolicyOrReviewOrSummary: function(obj) {
        this.onSuccessAction = function(response) {
            if (response.success) {
                pricematch.setUrl(response.url);
                jQuery(obj).parent().parent().parent().find('.return-policy-edit').html(response.value + "<span><a class='competitor-onesb-edit' onclick=pricematch.editReturnPolicyOrReviewOrSummary(this)>Edit</a></span>").show();
                jQuery(obj).parent().parent().hide()
            } else {
                this.getMsgObj().html(this.getErrorMsg(response.value)).show();
            }
        };
        this.onCompleteAction = function() {
            this.getMsgObj().hide();
        };
        this.setAjaxParameter({
            'data': jQuery(obj).parent().parent().find('textarea').val()
        });
        this.ajaxReuestMethod();
        return this;
    },
    changeOrderPrice: function(newQuoteItemPrices) {
        for (var key in newQuoteItemPrices) {
            if (newQuoteItemPrices.hasOwnProperty(key)) {
                if (jQuery("#item_use_custom_price_" + key).length) {
                if (jQuery("#item_use_custom_price_" + key).prop('checked') == false) {
                    jQuery("#item_use_custom_price_" + key).click();
                }
                }

                if (jQuery("#item_use_setsimple_custom_price_" + key).length) {
                    if (jQuery("#item_use_setsimple_custom_price_" + key).prop('checked') == false) {
                        jQuery("#item_use_setsimple_custom_price_" + key).click();
                    }
                }
                jQuery("#item_custom_price_" + key).val(newQuoteItemPrices[key]);
            }
        }
        jQuery(".update-items-qty button").click();
        var _this = this;
        setTimeout(function() {
            _this.priceMatchList(false);
        }, 3000);
    },
    editCompetitorUrl: function(obj) {
        var tdObj = jQuery(obj).closest('td');
        if (tdObj.find('.competitor-link').is(":hidden")) {
            tdObj.find('.competitor-link').show();
            tdObj.find('.edit-competitor-url').hide();
        }else{
            tdObj.find('.competitor-link').hide();
            tdObj.find('.edit-competitor-url').show();
        }
        return this;
    },
    cancelCompetitorUrl:function (obj) {
        var tdObj = jQuery(obj).closest('td');
        tdObj.find('input').each(function () {
            jQuery(this).val(jQuery(this).attr('oldValue'));
        });
        tdObj.find('.competitor-link').hide();
        tdObj.find('.edit-competitor-url').show();
        return this;
    },
    closeUrlPopup:function () {
        jQuery('.competitor-url-missing').hide();
        jQuery('.price-match-content').removeClass('missing-url-overlay');
    },
    setCompetitorShipping:function (shippingData) {
        this.options.shippingData = shippingData;
        return this;
    },
    changeShipping:function (value = null) {
        var shipping = this.options.shippingData;
        var type = shipping[value+'_type'];
        var charge = shipping[value+'_charge'];

        jQuery('#shipping-free-cost').attr('disabled', false);
        jQuery('#shipping-additional-cost').attr('disabled', false);
        jQuery('#shipping-cost-txt').attr('disabled', false);
        jQuery('#competitor-shipping').find('.validation-advice').hide();


        var validationObj = jQuery('#advice-validate-digits-range-shipping-cost-txt');
        if(validationObj.length){
            var txt = validationObj.text();
            var str = txt.substring(0, txt.indexOf('$'));
        }

        if (type == 'free') {
            jQuery('#shipping-free-cost').prop("checked", true);
            var txtobj = jQuery('#shipping-cost-txt');
            if(txtobj.hasClass('digits-range-'+charge+'-')){
                txtobj.removeClass('digits-range-'+charge+'-');
            }
            if(txtobj.hasClass('digits-range-'+shipping.wg_charge+'-')){
                txtobj.removeClass('digits-range-'+shipping.wg_charge+'-');
            }
            txtobj.addClass("validate-greater-than-zero").val(0);
        }else if (type == 'payable') {
            jQuery('#shipping-additional-cost').prop("checked", true);
            var txtobj = jQuery('#shipping-cost-txt');
            var value = (charge == undefined || charge == null)?0:charge;

            if(txtobj.hasClass('digits-range-'+shipping.threshold_charge+'-')){
                txtobj.removeClass('digits-range-'+shipping.threshold_charge+'-');
            }
            if(txtobj.hasClass('digits-range-'+shipping.wg_charge+'-')){
                txtobj.removeClass('digits-range-'+shipping.wg_charge+'-');
            }
            if (this.options.isEditShippingMethod) {
                txtobj.addClass('digits-range-'+value+'-');

                if(validationObj.length){
                    validationObj.text(str+' $'+value);
                }
            }


            txtobj.val(value);

        }else{
            jQuery('#shipping-free-cost').attr('disabled', true);
            jQuery('#shipping-additional-cost').attr('disabled', true);
            jQuery('#shipping-cost-txt').attr('disabled', true);
        }
        this.checkShippingMethodEditable(type);
    },
    setIsEditShippingMethod:function (isEditShippingMethod) {
        this.options.isEditShippingMethod = isEditShippingMethod;
        return this;
    },
    checkShippingMethodEditable: function (value) {
        var radio = jQuery('#shipping-free-cost');
        (this.options.isEditShippingMethod && value == 'payable')?radio.closest('div').hide():radio.closest('div').show();
        var txtobj = jQuery('#shipping-cost-txt');
        if (txtobj.hasClass('digits-range--')) {
            txtobj.removeClass('digits-range--').addClass('validate-greater-than-zero');
        }
        return this;
    }
}
pricematch = new Pricematch();