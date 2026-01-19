<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionController extends Controller
{
    public function RolesAndPermission()
    {
        $roles = Role::all();
        return view('admin.roles.manage_roles_and_permission', compact('roles'));
    }





    public function AddRole()
    {

        return view('admin.roles.add_roles');
    }

    public function StoreRole(Request $request)
    {

        $request->validate([
            'name' => 'required|string|max:255'
        ]);

        if (Role::where('name', $request->name)->exists()) {

            $notification = array(
                'message' => 'Role Already exist',
                'alert-type' => 'error'
            );

            return redirect()->back()->with($notification);
        }

        Role::create(['name' => $request->input('name')]);


        $notification = array(
            'message' => 'Role Created successfully',
            'alert-type' => 'success'
        );

        return redirect()->route('get_roles_and_permission')->with($notification);
    }

    public function AddPermission()
    {
        return view('admin.roles.add_permission');
    }

    public function StorePermission(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255'
        ]);


        if (Permission::where('name', $request->name)->exists()) {
            $notification = array(
                'message' => 'Permission Already exist',
                'alert-type' => 'error'
            );

            return redirect()->back()->with($notification);
        }

        Permission::create(['name' => $request->input('name')]);


        $notification = array(
            'message' => 'Permission Created successfully',
            'alert-type' => 'success'
        );

        return redirect()->route('get_roles_and_permission')->with($notification);
    }

    public function DeleteRole($id)
    {
        $role = Role::find($id);

        if (!is_null($role)) {
            $role->delete();

            $notification = array(
                'message' => 'Role deleted successfully',
                'alert-type' => 'success'
            );

            return redirect()->back()->with($notification);
        }
    }



    public function assignPermissionsView($roleId)
    {
        $role = Role::findById($roleId);
        $permissions = Permission::all();

        return view('admin.roles.assign_permissions', compact('role', 'permissions'));
    }

    public function assignPermissions(Request $request, $roleId)
    {
        $role = Role::findById($roleId);
        $permissions = $request->input('permissions', []);

        $role->syncPermissions($permissions);

        $notification = array(
            'message' => 'permission assigned successfully',
            'alert-type' => 'success'
        );

        return redirect()->route('get_roles_and_permission')->with('success', 'Permissions assigned successfully!')->with($notification);
    }
}
