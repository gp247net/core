<?php

namespace GP247\Core\Admin\Controllers\Auth;

use GP247\Core\Admin\Models\AdminPermission;
use GP247\Core\Admin\Models\AdminRole;
use GP247\Core\Admin\Controllers\RootAdminController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;

class LoginController extends RootAdminController
{
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

        return view('gp247-core::auth.login', ['title'=> gp247_language_render('admin.login')]);
    }

    /**
     * Handle a login request.
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function postLogin(Request $request)
    {
        $this->loginValidator($request->all())->validate();

        $credentials = $request->only([$this->username(), 'password']);
        $credentials['status'] = 1;
        $remember = $request->get('remember', false);

        if ($this->guard()->attempt($credentials, $remember)) {
            if (function_exists('gp247_event_admin_login')) {
                gp247_event_admin_login(admin()->user());
            }
            return $this->sendLoginResponse($request);
        }
        return back()->withInput()->withErrors([
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
            return gp247_language_render('admin.data_not_found_detail');
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
        return view('gp247-core::auth.setting')
            ->with($data);
    }

    public function putSetting()
    {
        $user = admin()->user();
        $data = request()->all();
        $dataOrigin = request()->all();
        $validator = Validator::make($dataOrigin, [
            'name' => 'required|string|max:100',
            'avatar' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:60|min:3|confirmed',
        ], [
            'username.regex' => gp247_language_render('admin.user.username_validate'),
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }
        //Edit

        $dataUpdate = [
            'name' => $data['name'],
            'avatar' => $data['avatar'],
        ];
        if ($data['password']) {
            $dataUpdate['password'] = bcrypt($data['password']);
        }
        $dataUpdate = gp247_clean($dataUpdate, [], true);
        $user->update($dataUpdate);

        return redirect()->route('admin.home')->with('success', gp247_language_render('action.edit_success'));
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
