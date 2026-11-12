<?php

namespace GP247\Core\Controllers\Auth;

use GP247\Core\Models\AdminPermission;
use GP247\Core\Models\AdminRole;
use GP247\Core\Models\AdminUser;
use GP247\Core\Controllers\RootAdminController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use GP247\Core\Controllers\PasswordValidationTrait;
use GP247\Core\Support\LoginThrottle;
use GP247\Core\Support\Password\LegacyEncodedPassword;

class LoginController extends RootAdminController
{
    use PasswordValidationTrait;
    public function __construct()
    {
        parent::__construct();
    }
    /**
     * Show the login page.
     *
     * @return \Illuminate\Contracts\View\Factory|Redirect|\Illuminate\View\View
     */
    public function getLogin()
    {
        if ($this->guard()->check()) {
            return redirect($this->redirectPath());
        }

        // Cutover (PA-1): the legacy login URL now renders the modern TailAdmin
        // login screen in-place. Presentation only — the form still posts to
        // admin.post_login, which owns validation, CSRF, the attempt limit and the guard.
        return view('gp247-admin::auth.login', ['title'=> gp247_language_render('admin.login')]);
    }

    /**
     * Handle a login request.
     *
     * Every attempt is counted before the password is checked, on the same counter
     * as the admin API login (GP247\Core\Support\LoginThrottle); past the limit the
     * password is not checked at all.
     *
     * @param Request $request
     *
     * @return mixed
     *
     * @aidlc-unit admin-shell
     * @aidlc-story US-AUI-007, US-AUI-admin-login-throttle
     * @aidlc-adr compat-foundation_rest-api-hardening
     */
    public function postLogin(Request $request)
    {
        $this->loginValidator($request->all())->validate();

        $username = (string) $request->input($this->username());
        $subject = LoginThrottle::subject(AdminUser::where($this->username(), $username)->value('id'), $username);
        $retryAfter = LoginThrottle::hit($request, 'admin', $subject);
        if ($retryAfter !== null) {
            return back()->withInput($request->except('password'))->withErrors([
                $this->username() => gp247_language_render('admin.login_too_many_attempts', ['seconds' => (string) $retryAfter]),
            ]);
        }

        $credentials = $request->only([$this->username(), 'password']);
        $credentials['status'] = 1;
        $remember = $request->get('remember', false);

        // WHY before attempt(): an account whose hash is of the old gp247_clean() form of this
        // password is repaired here, so attempt() below succeeds once and fires no Failed event.
        LegacyEncodedPassword::upgrade(
            AdminUser::where($this->username(), $username)->first(),
            (string) $request->input('password')
        );

        if ($this->guard()->attempt($credentials, $remember)) {
            LoginThrottle::succeeded($request, 'admin', $subject);
            // When this session signed in: a post-incident restore ends sessions older than it.
            $request->session()->put(\GP247\Core\Backup\Application\PostIncidentChecklist::SESSION_AUTH_AT, now()->getTimestamp());
            if (function_exists('gp247_event_admin_login')) {
                gp247_event_admin_login(admin()->user());
            }
            return $this->sendLoginResponse($request);
        }
        // WHY except('password'): withInput() would flash the typed password into the session store.
        return back()->withInput($request->except('password'))->withErrors([
            $this->username() => $this->getFailedLoginMessage(),
        ]);
    }

    /**
     * Get a validator for an incoming login request.
     *
     * @param array $data
     *
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function loginValidator(array $data)
    {
        return Validator::make($data, [
            $this->username() => 'required',
            'password' => 'required',
        ]);
    }

    /**
     * User logout.
     *
     * @return Redirect
     */
    public function getLogout(Request $request)
    {
        $this->guard()->logout();

        $request->session()->invalidate();

        return redirect()->back();
    }

    public function getSetting()
    {
        $user = admin()->user();
        if ($user === null) {
            return redirect(gp247_route_admin('admin.home'))->with('error', gp247_language_render('admin.display.data_not_found'));
        }
        $data = [
            'title' => gp247_language_render('admin.setting_account'),
            'subTitle' => '',
            'title_description' => '',
            'user' => $user,
            'roles' => (new AdminRole)->pluck('name', 'id')->all(),
            'permission' => (new AdminPermission)->pluck('name', 'id')->all(),
            'url_action' => gp247_route_admin('admin.post_setting'),
        ];
        return view('gp247-admin::auth.setting')
            ->with($data);
    }

    public function putSetting()
    {
        $user = admin()->user();
        $data = request()->all();
        $data = gp247_text_plain(data:$data, stripTags: true);
        $dataOrigin = request()->all();
        $validator = Validator::make($dataOrigin, [
            'name' => 'required|string|max:100',
            'avatar' => 'nullable|string|max:255',
            'email'    => 'nullable|string|email|max:255|unique:"'.AdminUser::class.'",email,' . $user->id . ',id',
            'password' => $this->rulePasswordNullable(),
        ], [
            'username.regex' => gp247_language_render('admin.user.username_validate'),
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }
        // WHY the raw input: a password is hashed exactly as typed, never through gp247_clean()
        // (NFR-SEC-credential-verbatim) — the cleaned form made passwords with & < > " unusable.
        $typedPassword = (string) ($dataOrigin['password'] ?? '');
        // WHY: after a post-incident restore the account may only continue with a NEW password.
        $mustChange = !empty($user->password_change_required);
        // WHY either form: the stored hash is of the typed password, or of its old gp247_clean()
        // form for an account set on this screen before the fix; neither may be chosen again
        // (this also keeps "old<b></b>" from passing as a new password).
        if ($mustChange && ($typedPassword === '' || LegacyEncodedPassword::matchesEitherForm($typedPassword, (string) $user->password))) {
            return redirect()->back()
                ->withErrors(['password' => gp247_language_render('admin.password_change_required')])
                ->withInput();
        }
        //Edit

        $dataUpdate = [
            'name' => $data['name'],
            'avatar' => $data['avatar'],
        ];

        if (admin()->user()->isRole('administrator')) {
            $dataUpdate['email'] = $data['email'];
        }
        if ($typedPassword !== '') {
            $dataUpdate['password'] = gp247_password_hash($typedPassword);
            if ($mustChange) {
                $dataUpdate['password_change_required'] = 0;
            }
        }
        $user->update($dataUpdate);

        return redirect(gp247_route_admin('admin.home'))->with('success', gp247_language_render('action.edit_success'));
    }

    /**
     * @return string|\Symfony\Component\Translation\TranslatorInterface
     */
    protected function getFailedLoginMessage()
    {
        return lang::has('auth.failed')
        ? gp247_language_render('admin.failed')
        : 'These credentials do not match our records.';
    }

    /**
     * Get the post login redirect path.
     *
     * @return string
     */
    protected function redirectPath()
    {
        return gp247_route_admin('admin.home');
    }

    /**
     * Send the response after the user was authenticated.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    protected function sendLoginResponse(Request $request)
    {
        $request->session()->regenerate();

        return redirect()->intended($this->redirectPath())->with(['success' => gp247_language_render('admin.login_successful')]);
    }

    /**
     * Get the login username to be used by the controller.
     *
     * @return string
     */
    protected function username()
    {
        return 'username';
    }

    /**
     * Get the guard to be used during authentication.
     *
     * @return \Illuminate\Contracts\Auth\StatefulGuard
     */
    protected function guard()
    {
        return Auth::guard('admin');
    }
}
