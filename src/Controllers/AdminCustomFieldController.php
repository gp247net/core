<?php
namespace GP247\Core\Controllers;

use GP247\Core\Controllers\RootAdminController;
use GP247\Core\Models\AdminCustomField;
use Validator;

class AdminCustomFieldController extends RootAdminController
{

    public function __construct()
    {
        parent::__construct();
    }
    public function index()
    {
        $data = [
            'title' => gp247_language_render('admin.custom_field.list'),
            'title_action' => '<i class="fa fa-plus" aria-hidden="true"></i> ' . gp247_language_render('admin.custom_field.add_new_title'),
            'subTitle' => '',
            'urlDeleteItem' => gp247_route_admin('admin_custom_field.delete'),
            'removeList' => 0, // 1 - Enable function delete list item
            'buttonRefresh' => 0, // 1 - Enable button refresh
            'url_action' => gp247_route_admin('admin_custom_field.create'),
        ];

        $listTh = [
            'type' => gp247_language_render('admin.custom_field.type'),
            'code' => gp247_language_render('admin.custom_field.code'),
            'name' => gp247_language_render('admin.custom_field.name'),
            'required' => gp247_language_render('admin.custom_field.required'),
            'option' => gp247_language_render('admin.custom_field.option'),
            'default' => gp247_language_render('admin.custom_field.default'),
            'status' => gp247_language_render('admin.custom_field.status'),
            'action' => gp247_language_render('action.title'),
        ];
        $obj = new AdminCustomField;
        $obj = $obj->orderBy('id', 'desc');
        $dataTmp = $obj->paginate(20);

        $dataTr = [];
        foreach ($dataTmp as $key => $row) {
            $arrAction = [
            '<a href="' . gp247_route_admin('admin_custom_field.edit', ['id' => $row['id'], 'page' => request('page')]) . '"  class="dropdown-item"><i class="fa fa-edit"></i> '.gp247_language_render('action.edit').'</a>',
            '<a href="#" onclick="deleteItem(\'' . $row['id'] . '\');"  title="' . gp247_language_render('action.delete') . '" class="dropdown-item"><i class="fas fa-trash-alt"></i> '.gp247_language_render('action.remove').'</a>',
            ];
            $action = $this->procesListAction($arrAction);

            $dataTr[$row['id']] = [
                'type' => gp247_custom_field_get_tables()[$row['type']] ?? $row['type'],
                'code' => $row['code'],
                'name' => $row['name'],
                'required' => $row['required'],
                'option' => $row['option'],
                'default' => $row['default'],
                'status' => $row['status'] ? '<span class="badge badge-success">ON</span>' : '<span class="badge badge-danger">OFF</span>',
                'action' => $action,
            ];
        }

        $data['listTh'] = $listTh;
        $data['dataTr'] = $dataTr;
        $data['pagination'] = $dataTmp->appends(request()->except(['_token', '_pjax']))->links('gp247-core::component.pagination');
        $data['resultItems'] = gp247_language_render('admin.result_item', ['item_from' => $dataTmp->firstItem(), 'item_to' => $dataTmp->lastItem(), 'total' =>  $dataTmp->total()]);

        $data['layout'] = 'index';
        $data['fieldTypes'] = gp247_custom_field_get_tables();
        $data['selectTypes'] = $this->selectTypes();
        return view('gp247-core::screen.custom_field')
            ->with($data);
    }


    /**
     * Post create new item in admin
     * @return [type] [description]
     */
    public function postCreate()
    {
        $data = request()->all();

        $validator = Validator::make($data, [
            'type' => 'required|string|max:100',
            'code' => 'required|string|max:100',
            'name' => 'required|string|max:250',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput($data);
        }
        
        $data['code'] = gp247_word_format_url($data['code']);
        $data['code'] = gp247_word_limit($data['code'], 100);
        $dataCreate = [
            'type' => $data['type'],
            'name' => $data['name'],
            'code' => $data['code'],
            'option' => $data['option'],
            'default' => $data['default'],
            'required' => (!empty($data['required']) ? 1 : 0),
            'status' => (!empty($data['status']) ? 1 : 0),
        ];
        $dataCreate = gp247_clean($dataCreate, ['default'], true);
        AdminCustomField::create($dataCreate);

        return redirect()->route('admin_custom_field.index')->with('success', gp247_language_render('action.create_success'));
    }

    /**
     * Form edit
     */
    public function edit($id)
    {
        $customField = AdminCustomField::find($id);
        if (!$customField) {
            return 'No data';
        }
        $data = [
            'title' => gp247_language_render('admin.custom_field.list'),
            'title_action' => '<i class="fa fa-edit" aria-hidden="true"></i> ' . gp247_language_render('action.edit'),
            'subTitle' => '',
            'urlDeleteItem' => gp247_route_admin('admin_custom_field.delete'),
            'removeList' => 0, // 1 - Enable function delete list item
            'buttonRefresh' => 0, // 1 - Enable button refresh
            'buttonSort' => 0, // 1 - Enable button sort
            'url_action' => gp247_route_admin('admin_custom_field.post_edit', ['id' => $customField['id']]),
            'customField' => $customField,
            'id' => $id,
        ];

        $listTh = [
            'type' => gp247_language_render('admin.custom_field.type'),
            'code' => gp247_language_render('admin.custom_field.code'),
            'name' => gp247_language_render('admin.custom_field.name'),
            'required' => gp247_language_render('admin.custom_field.required'),
            'option' => gp247_language_render('admin.custom_field.option'),
            'default' => gp247_language_render('admin.custom_field.default'),
            'status' => gp247_language_render('admin.custom_field.status'),
            'action' => gp247_language_render('action.title'),
        ];
        $obj = new AdminCustomField;
        $obj = $obj->orderBy('id', 'desc');
        $dataTmp = $obj->paginate(20);

        $dataTr = [];
        foreach ($dataTmp as $key => $row) {
            $arrAction = [
            '<a href="' . gp247_route_admin('admin_custom_field.edit', ['id' => $row['id'], 'page' => request('page')]) . '"  class="dropdown-item"><i class="fa fa-edit"></i> '.gp247_language_render('action.edit').'</a>',
            '<a href="#" onclick="deleteItem(\'' . $row['id'] . '\');"  title="' . gp247_language_render('action.delete') . '" class="dropdown-item"><i class="fas fa-trash-alt"></i> '.gp247_language_render('action.remove').'</a>',
            ];
            $action = $this->procesListAction($arrAction);

            $dataTr[$row['id']] = [
                'type' => gp247_custom_field_get_tables()[$row['type']] ?? $row['type'],
                'code' => $row['code'],
                'name' => $row['name'],
                'required' => $row['required'],
                'option' => $row['option'],
                'default' => $row['default'],
                'status' => $row['status'] ? '<span class="badge badge-success">ON</span>' : '<span class="badge badge-danger">OFF</span>',
                'action' => $action,
            ];
        }

        $data['listTh'] = $listTh;
        $data['dataTr'] = $dataTr;
        $data['pagination'] = $dataTmp->appends(request()->except(['_token', '_pjax']))->links('gp247-core::component.pagination');
        $data['resultItems'] = gp247_language_render('admin.result_item', ['item_from' => $dataTmp->firstItem(), 'item_to' => $dataTmp->lastItem(), 'total' =>  $dataTmp->total()]);
        $data['fieldTypes'] = gp247_custom_field_get_tables();
        $data['selectTypes'] = $this->selectTypes();
        $data['layout'] = 'edit';
        return view('gp247-core::screen.custom_field')
            ->with($data);
    }


    /**
     * update status
     */
    public function postEdit($id)
    {
        $customField = AdminCustomField::find($id);
        $data = request()->all();
        $validator = Validator::make($data, [
            'type' => 'required|string|max:100',
            'code' => 'required|string|max:100',
            'name' => 'required|string|max:250',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput($data);
        }
        //Edit
        $data['code'] = gp247_word_format_url($data['code']);
        $data['code'] = gp247_word_limit($data['code'], 100);
        $dataUpdate = [
            'type' => $data['type'],
            'name' => $data['name'],
            'code' => $data['code'],
            'option' => $data['option'],
            'default' => $data['default'],
            'required' => (!empty($data['required']) ? 1 : 0),
            'status' => (!empty($data['status']) ? 1 : 0),
        ];
        $dataUpdate = gp247_clean($dataUpdate, ['default'], true);

        $customField->update($dataUpdate);
        
        return redirect()->back()->with('success', gp247_language_render('action.edit_success'));
    }

    /*
    Delete list item
    Need mothod destroy to boot deleting in model
    */
    public function deleteList()
    {
        if (!request()->ajax()) {
            return response()->json(['error' => 1, 'msg' => gp247_language_render('admin.method_not_allow')]);
        } else {
            $ids = request('ids');
            $arrID = explode(',', $ids);
            AdminCustomField::destroy($arrID);
            return response()->json(['error' => 0, 'msg' => gp247_language_render('action.delete_success')]);
        }
    }

    public function selectTypes() {
        return [
            'text'     => 'Text', 
            'number'   => 'Number', 
            'date'     => 'Date', 
            'month'    => 'Month', 
            'week'     => 'Week', 
            'time'     => 'Time', 
            'color'    => 'Color', 
            'email'    => 'Email', 
            'password' => 'Password', 
            'url'      => 'Url', 
            'range'    => 'Range', 
            'textarea' => 'Textarea', 
            'select'   => 'Select', 
            'radio'    => 'Radio', 
            'checkbox' => 'Check box',
        ];
    }
}
