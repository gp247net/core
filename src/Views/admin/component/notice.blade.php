
@php
    $countNotice = \GP247\Core\Models\AdminNotice::getCountNoticeNew();
    if ($countNotice) {
      $badgeStatus = 'badge-warning';
    } else {
      $badgeStatus = 'badge-secondary';
    }
    $topNotice = \GP247\Core\Models\AdminNotice::getTopNotice();
@endphp
<li class="nav-item dropdown">
    <a class="nav-link" data-toggle="dropdown" href="#">
      <i class="far fa-bell"></i>
      <span class="badge {{ $badgeStatus }} navbar-badge">{{ $countNotice }}</span>
    </a>
    <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right notice">
  @if ($topNotice->count())
  <span class="dropdown-item dropdown-header text-right"><a href="{{ gp247_route_admin('admin_notice.mark_read') }}">{{ gp247_language_render('admin_notice.mark_read') }}</a></span>
    @foreach ($topNotice as $notice)
      <div class="dropdown-divider"></div>
      <a href="{{ gp247_route_admin('admin_notice.url',['type' => $notice->type,'typeId' => $notice->type_id]) }}" class="dropdown-item notice-{{ $notice->status ? 'read':'unread' }}">
        @if (in_array($notice->type, ['gp247_order_created', 'gp247_order_success', 'gp247_order_update_status']))
        <i class="fas fa-cart-plus"></i>
        @elseif(in_array($notice->type, ['gp247_customer_created']))
        <i class="fas fa-users"></i>
        @else
        <i class="far fa-bell"></i>
        @endif
        {{ gp247_content_render($notice->content) }}
      <span class="text-muted notice-time">[{{ $notice->admin->name ?? $notice->admin_id}}] {{ gp247_datetime_to_date($notice->created_at, 'Y-m-d H:i:s') }}</span>
      </a>
    @endforeach
    <div class="dropdown-divider"></div>
      <a href="{{ gp247_route_admin('admin_notice.index') }}" class="dropdown-item text-center">{{ gp247_language_render('action.view_more') }}</a>
    </div>
  @else
    <div class="dropdown-divider"></div>
    <span class="dropdown-item dropdown-header">{{ gp247_language_render('admin_notice.empty') }}</span>
  @endif
  </li>
