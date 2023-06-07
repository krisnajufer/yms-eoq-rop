<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\BarangCounter;
use Illuminate\Http\Request;
use App\Models\Admin\PengirimanCounter;
use App\Models\Admin\PermintaanCounter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\DataTables;

class PengirimanCounterController extends Controller
{
    public function userAuth()
    {
        $user = Auth::guard('user')->user();
        return $user;
    }

    public function index()
    {
        $pengiriman_counter_id = PengirimanCounter::generatePengirimanCounterId('C00002');
        dd($pengiriman_counter_id);
    }

    public function show($slug)
    {
        $user = $this->userAuth();
        $permintaan = DB::table('permintaan_counters')
            ->where('slug', $slug)
            ->first();
        $data = DB::table('pengiriman_counters')
            ->where('permintaan_counter_id', $permintaan->permintaan_counter_id)
            ->first();

        $query = 'SELECT a.pengiriman_counter_id, a.slug, a.permintaan_counter_id, g.name, d.nama_barang, b.jumlah_pengiriman, b.persetujuan, b.catatan
            FROM pengiriman_counters AS a
            LEFT JOIN detail_pengiriman_counters AS b ON a.pengiriman_counter_id = b.pengiriman_counter_id
            LEFT JOIN permintaan_counters AS c on a.permintaan_counter_id = c.permintaan_counter_id
            LEFT JOIN barangs AS d ON b.barang_id = d.barang_id
            LEFT JOIN gudangs AS e ON b.gudang_id = e.gudang_id
            LEFT JOIN counters AS f ON b.counter_id = f.counter_id
            LEFT JOIN users AS g ON e.user_id=g.user_id OR f.user_id = g.user_id WHERE a.permintaan_counter_id = "' . $permintaan->permintaan_counter_id . '"';

        $pengirimans = DB::select($query);

        return view('pages.pengiriman.detail', compact('user', 'pengirimans', 'permintaan', 'data'));
    }

    public function storePenerimaan($slug)
    {
        $pengiriman = PengirimanCounter::where('slug', $slug)->first();
        $permintaan = PermintaanCounter::where('permintaan_counter_id', $pengiriman->permintaan_counter_id)->first();
        $details = DB::table('detail_pengiriman_counters')->where('pengiriman_counter_id', $pengiriman->pengiriman_counter_id)->get();
        DB::beginTransaction();
        try {
            $permintaan->status = 'Diterima/Selesai';
            $permintaan->save();
            $pengiriman->tanggal_penerimaan = Carbon::now();
            $pengiriman->save();
            foreach ($details as $detail) {
                if ($detail->persetujuan == 'Setuju') {
                    $barang_counter = BarangCounter::where(['barang_id' => $detail->barang_id, 'counter_id' => $permintaan->counter_id])->first();
                    $barang_counter->stok_masuk += $detail->jumlah_pengiriman;
                    $barang_counter->save();
                }
            }
            DB::commit();
        } catch (\Exception $ex) {
            //throw $th;
            echo $ex->getMessage();
            DB::rollBack();
        }
        return redirect()->route('permintaan-counter');
    }

    public function indexBarangDiambil(Request $request)
    {
        $user = $this->userAuth();
        if ($request->ajax()) {
            $counter = DB::table('counters')
                ->where('user_id', $user->user_id)
                ->first();

            $barang_diambil = DB::table('detail_pengiriman_counters as dp')
                ->join('pengiriman_counters as pg', 'dp.pengiriman_counter_id', '=', 'pg.pengiriman_counter_id')
                ->join('permintaan_counters as pm', 'pg.permintaan_counter_id', '=', 'pm.permintaan_counter_id')
                ->join('barangs as b', 'dp.barang_id', '=', 'b.barang_id')
                ->join('barang_counters as bc', 'b.barang_id', '=', 'bc.barang_id')
                ->join('counters as c', 'pm.counter_id', '=', 'c.counter_id')
                ->join('users as u', 'c.user_id', '=', 'u.user_id')
                ->selectRaw('DISTINCT b.nama_barang, pm.tanggal_permintaan,dp.jumlah_pengiriman, u.name, pg.tanggal_pengiriman')
                ->where('dp.counter_id', $counter->counter_id)
                ->get();

            return DataTables::of($barang_diambil)->make(true);
        }


        return view('pages.history.barang-diambil', compact('user'));
    }
}
