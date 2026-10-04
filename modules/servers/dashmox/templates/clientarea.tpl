{* What the customer sees about their hosting, and nothing they can press.

   Read-only by decision, 2026-09-23: every change stays in the panel so it is
   built once. What this earns is the trip not taken: disk is fine, the
   certificate renews next month, nothing to do.

   Every figure can be absent on its own without taking the page with it. A
   panel that is unreachable, a website it has forgotten and a number never
   measured are three different sentences, and "not measured" is never drawn as
   a reassuring zero. *}

{if $problem}
  <div class="alert alert-warning">{$problem|escape}</div>
{/if}

{if $site}
  <div class="panel panel-default">
    <div class="panel-heading">
      <h3 class="panel-title">{$site.domain|escape}</h3>
    </div>
    <table class="table table-condensed">
      <tbody>
        <tr>
          <th style="width:40%">Status</th>
          <td>
            {if $site.status == 'active'}
              <span class="label label-success">Active</span>
            {elseif $site.status == 'suspended'}
              <span class="label label-warning">Suspended</span>
            {else}
              <span class="label label-default">{$site.status|escape}</span>
            {/if}
            {if $site.runtime}<span class="text-muted"> &middot; {$site.runtime|escape}</span>{/if}
          </td>
        </tr>
        <tr>
          <th>Disk used</th>
          <td>
            {if $disk === null}
              <span class="text-muted">Not measured yet</span>
            {else}
              {($disk / 1048576)|string_format:"%.1f"} MB
            {/if}
          </td>
        </tr>
        <tr>
          <th>Transfer, last 30 days</th>
          <td>
            {if $transfer === null}
              <span class="text-muted">Not measured yet</span>
            {else}
              {($transfer / 1048576)|string_format:"%.1f"} MB
            {/if}
          </td>
        </tr>
        <tr>
          <th>Certificate</th>
          <td>
            {if $sslDays === null}
              <span class="text-muted">None yet</span>
            {elseif $sslDays < 0}
              <span class="label label-danger">Expired</span>
            {elseif $sslDays < 14}
              <span class="label label-warning">Expires in {$sslDays} days</span>
            {else}
              Expires in {$sslDays} days
            {/if}
          </td>
        </tr>
      </tbody>
    </table>
    {if $panel}
      <div class="panel-footer">
        <a href="{$panel|escape}" target="_blank" rel="noopener" class="btn btn-default btn-sm">
          Open the hosting panel
        </a>
        <span class="text-muted"> Sign in with your panel password.</span>
      </div>
    {/if}
  </div>
{/if}
