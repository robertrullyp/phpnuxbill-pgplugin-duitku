{include file="sections/header.tpl"}

<form class="form-horizontal" method="post" role="form" action="{$_url}paymentgateway/duitku">
    <div class="row">
        <div class="col-sm-12">
            <div class="panel panel-primary panel-hovered panel-stacked mb30">
                <div class="panel-heading">DUITKU</div>
                <div class="panel-body">
                    <div class="alert alert-info">
                        Mode sandbox/production Duitku diatur dari halaman ini dan tidak lagi mengikuti app stage.
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">{Lang::T('Kode Merchant')}</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="duitku_merchant_id" name="duitku_merchant_id" placeholder="D" value="{$duitku_settings.merchant_id}">
                            <a href="https://duitku.com/merchant/Project" target="_blank" rel="noopener" class="help-block">https://duitku.com/merchant/Project</a>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">Merchant/API Key</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="duitku_merchant_key" name="duitku_merchant_key" placeholder="xxxxxxxxxxxxxxxxx" value="{$duitku_settings.merchant_key}">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">Environment</label>
                        <div class="col-md-6">
                            <select class="form-control" name="duitku_environment" id="duitku_environment">
                                <option value="sandbox" {if $duitku_settings.environment eq 'sandbox'}selected{/if}>Sandbox</option>
                                <option value="production" {if $duitku_settings.environment eq 'production'}selected{/if}>Production</option>
                            </select>
                            <span class="help-block">Gunakan sandbox untuk testing dan production hanya dengan merchant code/key production.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">Integration Mode</label>
                        <div class="col-md-6">
                            <select class="form-control" name="duitku_integration_mode" id="duitku_integration_mode">
                                <option value="v2_direct" {if $duitku_settings.integration_mode eq 'v2_direct'}selected{/if}>V2 Direct - pilih channel di customer panel</option>
                                <option value="pop_redirect" {if $duitku_settings.integration_mode eq 'pop_redirect'}selected{/if}>POP Redirect - halaman pembayaran hosted Duitku</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">Expiry Period</label>
                        <div class="col-md-3">
                            <input type="number" min="0" class="form-control" id="duitku_expiry_period" name="duitku_expiry_period" placeholder="Default Duitku" value="{if $duitku_settings.expiry_period gt 0}{$duitku_settings.expiry_period}{/if}">
                            <span class="help-block">Menit. Kosong/0 mengikuti default per channel Duitku.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">Account Link Credential</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="duitku_account_link_credential_code" name="duitku_account_link_credential_code" value="{$duitku_settings.account_link_credential_code}" placeholder="Opsional untuk SL/OL">
                            <span class="help-block">Jika kosong, channel Shopee/OVO Account Link tidak ditampilkan di checkout.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">{Lang::T('Url Callback Proyek')}</label>
                        <div class="col-md-6">
                            <input type="text" readonly class="form-control" onclick="this.select()" value="{$_url}callback/duitku">
                            <a href="https://duitku.com/merchant/Project" target="_blank" rel="noopener" class="help-block">https://duitku.com/merchant/Project</a>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">{Lang::T('Channels')}</label>
                        <div class="col-md-10">
                            <div class="row">
                                {foreach $channels as $channel}
                                    <div class="col-sm-6 col-md-4">
                                        <label class="checkbox-inline" style="display:block; margin:0 0 10px;">
                                            <input type="checkbox" {if in_array($channel['id'], $duitku_settings.enabled_channels)}checked{/if} name="duitku_channel[]" value="{$channel['id']}">
                                            <strong>{$channel['id']}</strong> {$channel['name']}
                                            {if $channel['account_link']}<span class="label label-warning">Account Link</span>{/if}
                                        </label>
                                    </div>
                                {/foreach}
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="col-lg-offset-2 col-lg-10">
                            <button class="btn btn-primary waves-effect waves-light" type="submit">{Lang::T('Save Change')}</button>
                        </div>
                    </div>

                    <pre>/ip hotspot walled-garden
add dst-host=duitku.com
add dst-host=*.duitku.com</pre>
                    <small class="form-text text-muted">{Lang::T('Set Telegram Bot to get any error and notification')}</small>
                </div>
            </div>
        </div>
    </div>
</form>

{include file="sections/footer.tpl"}
