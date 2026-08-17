{**
 * Feed-Pruefbericht fuer das Backoffice.
 *}
<div class="panel">
    <div class="panel-heading">
        <i class="icon-check"></i> {l s='Ergebnis der Feed-Pruefung' mod='internautengooglefeed'}
    </div>

    <div class="row">
        <div class="col-lg-3">
            <p><strong>{l s='Gueltige Items' mod='internautengooglefeed'}:</strong> {$igf_item_count|intval}</p>
        </div>
        <div class="col-lg-3">
            <p><strong>{l s='Ausgelassen' mod='internautengooglefeed'}:</strong> {$igf_skipped_count|intval}</p>
        </div>
        <div class="col-lg-3">
            <p><strong>{l s='Fehler' mod='internautengooglefeed'}:</strong> <span
                    class="text-danger">{$igf_error_count|intval}</span></p>
        </div>
        <div class="col-lg-3">
            <p><strong>{l s='Warnungen' mod='internautengooglefeed'}:</strong> <span
                    class="text-warning">{$igf_warning_count|intval}</span></p>
        </div>
    </div>

    {if $igf_issues|@count == 0}
        <div class="alert alert-success">
            {l s='Keine Probleme gefunden. Alle Produkte sind fuer Google Merchant gueltig.' mod='internautengooglefeed'}
        </div>
    {else}
        {if $igf_issues_truncated}
            <div class="alert alert-info">
                {l s='Es werden nur die ersten %d Eintraege angezeigt.' sprintf=[$igf_report_max_rows] mod='internautengooglefeed'}
            </div>
        {/if}

        <table class="table">
            <thead>
                <tr>
                    <th>{l s='Schwere' mod='internautengooglefeed'}</th>
                    <th>{l s='Produkt-ID' mod='internautengooglefeed'}</th>
                    <th>{l s='Feed-ID' mod='internautengooglefeed'}</th>
                    <th>{l s='Produkt' mod='internautengooglefeed'}</th>
                    <th>{l s='Problem' mod='internautengooglefeed'}</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$igf_issues item=issue}
                    <tr>
                        <td>
                            {if $issue.severity == 'error'}
                                <span class="label label-danger">{l s='Fehler' mod='internautengooglefeed'}</span>
                            {else}
                                <span class="label label-warning">{l s='Warnung' mod='internautengooglefeed'}</span>
                            {/if}
                        </td>
                        <td>{$issue.id_product|intval}</td>
                        <td>{$issue.reference|escape:'html':'UTF-8'}</td>
                        <td>{$issue.name|escape:'html':'UTF-8'}</td>
                        <td>{$issue.message|escape:'html':'UTF-8'}</td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    {/if}
</div>