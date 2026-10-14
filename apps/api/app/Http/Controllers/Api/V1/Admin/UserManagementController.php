<?php
namespace App\Http\Controllers\Api\V1\Admin;
use App\Authorization\Permissions;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\LocalCredential;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class UserManagementController extends ApiController
{
    private function authorizeAdmin(): void { Gate::authorize(Permissions::SETTINGS_MANAGE); }
    public function index(Request $request) { $this->authorizeAdmin(); $q=trim((string)$request->query('q','')); $users=User::when($q,fn($x)=>$x->where(fn($w)=>$w->where('name','ilike',"%$q%")->orWhere('email','ilike',"%$q%")->orWhere('username','ilike',"%$q%")))->orderBy('name')->paginate(min(100,max(1,(int)$request->query('perPage',25)))); return response()->json(['data'=>$users->through(fn($u)=>$this->row($u)),'meta'=>['current_page'=>$users->currentPage(),'last_page'=>$users->lastPage(),'total'=>$users->total()]]); }
    public function show(string $id) { $this->authorizeAdmin(); $u=User::findOrFail($id); return response()->json(['data'=>$this->row($u,true)]); }
    public function store(Request $request) { $this->authorizeAdmin(); $d=$request->validate(['name'=>['required','string','max:200'],'email'=>['required','email','max:200','unique:users,email'],'username'=>['required','string','max:120','alpha_dash','unique:users,username'],'area'=>['nullable','string','max:200']]); $u=DB::transaction(function()use($d){$u=User::create([...$d,'email'=>mb_strtolower($d['email']),'username'=>mb_strtolower($d['username']),'auth_source'=>'local','is_active'=>false,'roles'=>[],'groups'=>[]]); LocalCredential::create(['user_id'=>$u->id,'password_hash'=>password_hash(Str::random(64),PASSWORD_ARGON2ID),'password_changed_at'=>now()]); AuditLogger::record('directory.user_created','user',$u->id,['source'=>'local','status'=>'invitation_pending'],null,$this->user(),context:['object_name'=>$u->name]); return $u;}); return response()->json(['data'=>$this->row($u)],201); }
    private function row(User $u,bool $detail=false): array { return ['id'=>$u->id,'name'=>$u->name,'email'=>$u->email,'username'=>$u->username,'area'=>$u->area,'authSource'=>$u->auth_source,'status'=>$u->is_active?'active':'inactive','roles'=>$u->roles??[],'groups'=>$u->groups??[],'entraOid'=>$u->entra_oid,'entraTid'=>$u->entra_tid,'lastSeenAt'=>$u->last_seen_at?->toIso8601String(),'directorySyncedAt'=>$u->directory_synced_at?->toIso8601String(),...($detail?['createdAt'=>$u->created_at?->toIso8601String(),'updatedAt'=>$u->updated_at?->toIso8601String()]:[])]; }
}
