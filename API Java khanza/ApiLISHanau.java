package bridging;

import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.databind.node.ArrayNode;
import com.fasterxml.jackson.databind.node.ObjectNode;
import fungsi.akses;
import fungsi.koneksiDB;
import fungsi.sekuel;
import java.security.KeyManagementException;
import java.security.NoSuchAlgorithmException;
import java.security.SecureRandom;
import java.security.cert.CertificateException;
import java.security.cert.X509Certificate;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import javax.net.ssl.SSLContext;
import javax.net.ssl.X509TrustManager;
import javax.swing.JOptionPane;
import org.apache.http.conn.scheme.Scheme;
import org.apache.http.conn.ssl.SSLSocketFactory;
import org.springframework.http.HttpEntity;
import org.springframework.http.HttpHeaders;
import org.springframework.http.HttpMethod;
import org.springframework.http.MediaType;
import org.springframework.http.client.HttpComponentsClientHttpRequestFactory;
import org.springframework.web.client.RestTemplate;

/**
 * Bridging SIMRS Khanza ke LIS RSUD Hanau.
 *
 * Mengikuti pola vendor LIS yang sudah ada pada Khanza (ApiSOFTMEDIX,
 * ApiMEDQLAB, ApiLICA): satu kelas berisi kirimRalan / kirimRanap / ambil,
 * dipanggil dari tombol pada DlgCariPermintaanLab.
 *
 * ARAH DATA
 *
 *   kirimRalan/kirimRanap : Khanza  ->  LIS   POST /api/v1/khanza/orders
 *   ambil                 : LIS     ->  Khanza GET /api/v1/khanza/hasil?noorder=
 *
 * Hasil yang diambil ditulis ke temporary_permintaan_lab, tabel singgahan
 * yang sama dipakai seluruh vendor lain, lalu dibaca DlgPeriksaLaboratorium
 * lewat setOrderLISHanau(). Tata letak kolomnya mengikuti yang sudah baku:
 *
 *   temp1 = noorder      temp2 = nama pemeriksaan   temp3 = nilai hasil
 *   temp4 = nilai rujukan temp5 = satuan            temp6 = flag
 *   temp7 = id_template  temp37 = alamat IP pemakai
 *
 * temp7 dipadankan dengan template_laboratorium.id_template, sehingga
 * pemetaan pemeriksaan tetap memakai kunci Khanza yang sudah ada
 * (kd_jenis_prw + id_template) — bukan kunci baru.
 *
 * KEAMANAN
 *
 * Setiap permintaan membawa X-API-Key. Bila secret terisi, badan
 * permintaan ikut ditandatangani HMAC-SHA256 pada header X-Signature —
 * skema yang sama dengan yang diverifikasi LIS.
 *
 * Pengaturan dibaca dari setting/database.xml lewat koneksiDB, sama
 * seperti vendor lain:
 *
 *   URLAPILISHANAU      alamat dasar LIS, mis. http://192.168.0.108:8080
 *   APIKEYLISHANAU      API key bercakupan "khanza" (terenkripsi)
 *   APISECRETLISHANAU   secret HMAC (terenkripsi, boleh kosong)
 */
public class ApiLISHanau {

    private Connection koneksi = koneksiDB.condb();
    private PreparedStatement ps, ps2;
    private ResultSet rs, rs2;
    private String URLAPILISHANAU = "", APIKEYLISHANAU = "", APISECRETLISHANAU = "", stringbalik = "";
    private HttpHeaders headers;
    private HttpEntity requestEntity;
    private JsonNode root, response;
    private sekuel Sequel = new sekuel();
    private ObjectMapper mapper = new ObjectMapper();
    private int i = 0;

    public ApiLISHanau() {
        super();
        try {
            URLAPILISHANAU    = koneksiDB.URLAPILISHANAU();
            APIKEYLISHANAU    = koneksiDB.APIKEYLISHANAU();
            APISECRETLISHANAU = koneksiDB.APISECRETLISHANAU();
        } catch (Exception e) {
            System.out.println("Notif : " + e);
        }
    }

    public void kirimRalan(String nopermintaan) {
        kirim(nopermintaan, "ralan");
    }

    public void kirimRanap(String nopermintaan) {
        kirim(nopermintaan, "ranap");
    }

    /**
     * Kirim satu permintaan lab ke LIS.
     *
     * Kueri penggabungannya sengaja sama persis dengan yang dipakai vendor
     * lain di Khanza: permintaan_lab tidak memuat identitas pasien, jadi
     * nama, nomor rekam medis, jenis kelamin, tanggal lahir, ruangan, dan
     * cara bayar harus dirangkai lewat no_rawat.
     *
     * LEFT JOIN dipakai, bukan INNER JOIN. Dengan INNER JOIN, satu baris
     * induk yang hilang — dokter perujuk yang sudah dinonaktifkan, poli
     * yang dihapus — membuat permintaannya lenyap dari hasil kueri tanpa
     * pesan apa pun, dan petugas hanya melihat "tidak ada data".
     */
    private void kirim(String nopermintaan, String posisi) {
        if (URLAPILISHANAU.isEmpty() || APIKEYLISHANAU.isEmpty()) {
            JOptionPane.showMessageDialog(null,
                "Alamat atau API key LIS RSUD Hanau belum diisi pada setting/database.xml.");
            return;
        }

        try {
            ps = koneksi.prepareStatement(
                "select permintaan_lab.noorder, permintaan_lab.no_rawat, reg_periksa.no_rkm_medis, " +
                "pasien.nm_pasien, pasien.jk, pasien.alamat, pasien.email, " +
                "date_format(pasien.tgl_lahir,'%Y-%m-%d') as tgl_lahir, " +
                "date_format(permintaan_lab.tgl_permintaan,'%Y-%m-%d') as tgl_permintaan, " +
                "permintaan_lab.jam_permintaan, " +
                "if(permintaan_lab.tgl_sampel='0000-00-00','', " +
                "   date_format(permintaan_lab.tgl_sampel,'%Y-%m-%d')) as tgl_sampel, " +
                "if(permintaan_lab.jam_sampel='00:00:00','',permintaan_lab.jam_sampel) as jam_sampel, " +
                "permintaan_lab.dokter_perujuk as kode_dokter, dokter.nm_dokter, " +
                "reg_periksa.kd_poli, poliklinik.nm_poli, reg_periksa.kd_pj, penjab.png_jawab, " +
                "permintaan_lab.status, permintaan_lab.diagnosa_klinis, permintaan_lab.informasi_tambahan " +
                "from permintaan_lab " +
                "left join reg_periksa on permintaan_lab.no_rawat=reg_periksa.no_rawat " +
                "left join pasien      on reg_periksa.no_rkm_medis=pasien.no_rkm_medis " +
                "left join dokter      on permintaan_lab.dokter_perujuk=dokter.kd_dokter " +
                "left join poliklinik  on reg_periksa.kd_poli=poliklinik.kd_poli " +
                "left join penjab      on reg_periksa.kd_pj=penjab.kd_pj " +
                "where permintaan_lab.noorder=?");

            try {
                ps.setString(1, nopermintaan);
                rs = ps.executeQuery();

                if (!rs.next()) {
                    JOptionPane.showMessageDialog(null,
                        "Permintaan " + nopermintaan + " tidak ditemukan di database Khanza.");
                    return;
                }

                ObjectNode order = mapper.createObjectNode();
                order.put("noorder",            teks(rs.getString("noorder")));
                order.put("no_rawat",           teks(rs.getString("no_rawat")));
                order.put("no_rkm_medis",       teks(rs.getString("no_rkm_medis")));
                order.put("nm_pasien",          teks(rs.getString("nm_pasien")));
                order.put("jk",                 teks(rs.getString("jk")));
                order.put("tgl_lahir",          teks(rs.getString("tgl_lahir")));
                order.put("alamat",             teks(rs.getString("alamat")));
                order.put("email",              teks(rs.getString("email")));
                order.put("tgl_permintaan",     teks(rs.getString("tgl_permintaan")));
                order.put("jam_permintaan",     teks(rs.getString("jam_permintaan")));
                order.put("tgl_sampel",         teks(rs.getString("tgl_sampel")));
                order.put("jam_sampel",         teks(rs.getString("jam_sampel")));
                order.put("kode_dokter_perujuk", teks(rs.getString("kode_dokter")));

                // LIS menampilkan nama dokter, bukan kodenya. Bila dokter
                // sudah tidak ada di master, kode tetap dikirim supaya
                // medannya tidak kosong sama sekali.
                String namaDokter = teks(rs.getString("nm_dokter"));
                order.put("dokter_perujuk",
                    namaDokter.isEmpty() ? teks(rs.getString("kode_dokter")) : namaDokter);

                order.put("status",             teks(rs.getString("status")).isEmpty()
                                                    ? posisi : teks(rs.getString("status")));
                order.put("kode_ruang",         teks(rs.getString("kd_poli")));
                order.put("nama_ruang",         teks(rs.getString("nm_poli")));
                order.put("kode_carabayar",     teks(rs.getString("kd_pj")));
                order.put("nama_carabayar",     teks(rs.getString("png_jawab")));
                order.put("diagnosa_klinis",    teks(rs.getString("diagnosa_klinis")));
                order.put("informasi_tambahan", teks(rs.getString("informasi_tambahan")));

                // Rincian pemeriksaan yang diminta.
                ArrayNode detail = order.putArray("detail");
                ps2 = koneksi.prepareStatement(
                    "select permintaan_detail_permintaan_lab.kd_jenis_prw, " +
                    "permintaan_detail_permintaan_lab.id_template, " +
                    "template_laboratorium.Pemeriksaan, jns_perawatan_lab.nm_perawatan " +
                    "from permintaan_detail_permintaan_lab " +
                    "left join template_laboratorium on " +
                    "  template_laboratorium.kd_jenis_prw=permintaan_detail_permintaan_lab.kd_jenis_prw and " +
                    "  template_laboratorium.id_template=permintaan_detail_permintaan_lab.id_template " +
                    "left join jns_perawatan_lab on " +
                    "  jns_perawatan_lab.kd_jenis_prw=permintaan_detail_permintaan_lab.kd_jenis_prw " +
                    "where permintaan_detail_permintaan_lab.noorder=?");
                try {
                    ps2.setString(1, nopermintaan);
                    rs2 = ps2.executeQuery();
                    while (rs2.next()) {
                        ObjectNode d = detail.addObject();
                        d.put("kd_jenis_prw", teks(rs2.getString("kd_jenis_prw")));
                        d.put("id_template",  rs2.getInt("id_template"));
                        d.put("pemeriksaan",  teks(rs2.getString("Pemeriksaan")));
                        d.put("nm_perawatan", teks(rs2.getString("nm_perawatan")));
                    }
                } finally {
                    if (rs2 != null) { rs2.close(); }
                    if (ps2 != null) { ps2.close(); }
                }

                if (detail.size() == 0) {
                    JOptionPane.showMessageDialog(null,
                        "Permintaan " + nopermintaan + " belum memuat rincian pemeriksaan, " +
                        "sehingga tidak dikirim ke LIS.");
                    return;
                }

                String body = mapper.writeValueAsString(order);

                headers = new HttpHeaders();
                headers.setContentType(MediaType.APPLICATION_JSON);
                headers.set("X-API-Key", APIKEYLISHANAU);
                if (!APISECRETLISHANAU.isEmpty()) {
                    headers.set("X-Signature", hmac(body, APISECRETLISHANAU));
                }

                requestEntity = new HttpEntity(body, headers);
                stringbalik = getRest().exchange(
                    URLAPILISHANAU + "/api/v1/khanza/orders",
                    HttpMethod.POST, requestEntity, String.class).getBody();

                System.out.println("LIS Hanau kirim : " + stringbalik);
                root = mapper.readTree(stringbalik);

                // LIS membalas per order; ambil pesan yang paling menjelaskan.
                JsonNode hasil = root.path("data");
                String pesan = nilaiNode(root, "pesan");
                if (hasil.isArray() && hasil.size() > 0) {
                    JsonNode h = hasil.get(0);
                    String p = nilaiNode(h, "pesan");
                    if (!p.isEmpty()) {
                        pesan = p;
                    }
                    if (!nilaiNode(h, "no_lab").isEmpty()) {
                        pesan = pesan + "\nNo. Lab LIS : " + nilaiNode(h, "no_lab");
                    }
                    JsonNode belum = h.path("tidak_dipetakan");
                    if (belum.isArray() && belum.size() > 0) {
                        StringBuilder sb = new StringBuilder();
                        for (JsonNode b : belum) {
                            if (sb.length() > 0) { sb.append(", "); }
                            sb.append(b.asText());
                        }
                        pesan = pesan + "\n\nBelum dipetakan di LIS : " + sb;
                    }
                }

                JOptionPane.showMessageDialog(null, pesan.isEmpty() ? "Permintaan terkirim ke LIS." : pesan);

            } finally {
                if (rs != null) { rs.close(); }
                if (ps != null) { ps.close(); }
            }
        } catch (Exception ex) {
            System.out.println("Notifikasi : " + ex);
            JOptionPane.showMessageDialog(null, pesanGalat(ex));
        }
    }

    /**
     * Ambil hasil pemeriksaan dari LIS untuk satu permintaan.
     *
     * Hasil ditulis ke temporary_permintaan_lab, lalu dibaca
     * DlgPeriksaLaboratorium. Baris milik alamat IP ini dibersihkan lebih
     * dulu supaya sisa pengambilan sebelumnya tidak tercampur.
     */
    public void ambil(String nopermintaan) {
        if (URLAPILISHANAU.isEmpty() || APIKEYLISHANAU.isEmpty()) {
            JOptionPane.showMessageDialog(null,
                "Alamat atau API key LIS RSUD Hanau belum diisi pada setting/database.xml.");
            return;
        }

        try {
            headers = new HttpHeaders();
            headers.setContentType(MediaType.APPLICATION_JSON);
            headers.set("X-API-Key", APIKEYLISHANAU);
            requestEntity = new HttpEntity(headers);

            stringbalik = getRest().exchange(
                URLAPILISHANAU + "/api/v1/khanza/hasil?noorder=" + nopermintaan,
                HttpMethod.GET, requestEntity, String.class).getBody();

            System.out.println("LIS Hanau ambil : " + stringbalik);
            root = mapper.readTree(stringbalik);
            response = root.path("data");

            Sequel.queryu("delete from temporary_permintaan_lab where temp37='" + akses.getalamatip() + "'");

            if (!response.isArray() || response.size() == 0) {
                JOptionPane.showMessageDialog(null,
                    "Belum ada hasil terverifikasi di LIS untuk permintaan " + nopermintaan + ".");
                return;
            }

            i = 0;
            int jumlah = 0;
            System.out.println("Proses ambil hasil LIS RSUD Hanau :");

            for (JsonNode order : response) {
                String noorder = nilaiNode(order, "noorder");
                if (noorder.isEmpty()) {
                    noorder = nopermintaan;
                }

                for (JsonNode d : order.path("detail")) {
                    System.out.println(i + " " + noorder + " | " + d.path("pemeriksaan").asText() +
                        " | " + d.path("nilai").asText() + " | " + d.path("nilai_rujukan").asText() +
                        " | " + d.path("satuan").asText() + " | " + d.path("flag").asText() +
                        " | " + d.path("id_template").asText());

                    Sequel.menyimpan("temporary_permintaan_lab",
                        "'" + i + "','" + noorder + "','" +
                        bersih(d.path("pemeriksaan").asText()) + "','" +
                        bersih(d.path("nilai").asText()) + "','" +
                        bersih(d.path("nilai_rujukan").asText()) + "','" +
                        bersih(d.path("satuan").asText()) + "','" +
                        bersih(d.path("flag").asText()) + "','" +
                        d.path("id_template").asText() +
                        "','','','','','','','','','','','','','','','','','','','','','','','','','','','','','','" +
                        akses.getalamatip() + "'", "Periksa Lab");
                    i++;
                    jumlah++;
                }
            }

            if (jumlah == 0) {
                JOptionPane.showMessageDialog(null,
                    "Hasil ditemukan di LIS, tetapi tidak ada rincian pemeriksaan yang terpetakan " +
                    "ke template Khanza. Periksa Pemetaan Pemeriksaan di LIS.");
            }

        } catch (Exception ex) {
            System.out.println("Notifikasi : " + ex);
            JOptionPane.showMessageDialog(null, pesanGalat(ex));
        }
    }

    /**
     * Baca satu medan JSON sebagai teks, aman untuk semua versi Jackson 2.x.
     *
     * MENGAPA TIDAK asText("") SAJA
     *
     * Overload JsonNode.asText(String bawaan) baru ada sejak Jackson 2.4.
     * Classpath SIMRS Khanza memuat DUA jackson-databind sekaligus —
     * 2.2.3 dan 2.18.1 — dan yang lebih lama muncul lebih dulu, sehingga
     * javac memakai 2.2.3. Di sana asText() tidak menerima argumen sama
     * sekali, dan kompilasi gagal.
     *
     * Memakai asText() tanpa argumen lalu menyaring null/"null" sendiri
     * membuat kelas ini tidak bergantung pada versi mana yang kebetulan
     * menang di classpath.
     */
    private String nilaiNode(JsonNode induk, String medan) {
        if (induk == null) {
            return "";
        }
        JsonNode n = induk.path(medan);
        if (n == null || n.isMissingNode() || n.isNull()) {
            return "";
        }
        String v = n.asText();
        if (v == null || v.equals("null")) {
            return "";
        }
        return v.trim();
    }

    /** Kosongkan null agar tidak menjadi teks "null" pada JSON maupun tabel. */
    private String teks(String v) {
        return v == null ? "" : v.trim();
    }

    /**
     * Buang karakter yang dapat memutus perintah SQL.
     *
     * Sequel.menyimpan() merangkai nilai sebagai teks, bukan parameter
     * terikat. Nilai dari LIS sudah tepercaya, tetapi tanda kutip pada
     * keterangan pemeriksaan tetap dapat merusak perintahnya, jadi
     * dibersihkan lebih dulu.
     */
    private String bersih(String v) {
        if (v == null) {
            return "";
        }
        return v.replace("\\", " ").replace("'", " ").replace("\"", " ")
                .replace("\r", " ").replace("\n", " ").trim();
    }

    /** HMAC-SHA256 heksadesimal — skema yang sama dengan yang diperiksa LIS. */
    private String hmac(String body, String secret) throws Exception {
        Mac mac = Mac.getInstance("HmacSHA256");
        mac.init(new SecretKeySpec(secret.getBytes("UTF-8"), "HmacSHA256"));
        byte[] raw = mac.doFinal(body.getBytes("UTF-8"));
        StringBuilder sb = new StringBuilder(raw.length * 2);
        for (byte b : raw) {
            sb.append(Character.forDigit((b >> 4) & 0xF, 16));
            sb.append(Character.forDigit(b & 0xF, 16));
        }
        return sb.toString();
    }

    /**
     * Terjemahkan kegagalan menjadi kalimat yang dapat ditindaklanjuti.
     *
     * Pesan bawaan Spring seperti "401 Unauthorized" tidak memberi tahu
     * petugas apa yang harus diperbaiki.
     */
    private String pesanGalat(Exception ex) {
        String t = ex.toString();

        if (t.contains("UnknownHostException") || t.contains("ConnectException")
            || t.contains("ConnectTimeout")) {
            return "LIS RSUD Hanau tidak dapat dihubungi di " + URLAPILISHANAU +
                   ".\nPeriksa alamat, jaringan, dan apakah aplikasi LIS sedang berjalan.";
        }
        if (t.contains("401")) {
            return "LIS menolak kredensial.\nSamakan APIKEYLISHANAU pada setting/database.xml " +
                   "dengan Kredensial API bercakupan \"khanza\" di LIS.";
        }
        if (t.contains("403")) {
            return "LIS menolak alamat IP komputer ini.\nTambahkan pada Batas IP kredensial API di LIS.";
        }
        if (t.contains("404")) {
            return "Alamat endpoint LIS tidak ditemukan.\nPeriksa URLAPILISHANAU — isinya alamat " +
                   "dasar LIS saja, tanpa /api/v1.";
        }
        if (t.contains("SocketTimeout") || t.contains("ReadTimeout")) {
            return "LIS terlalu lama menjawab.\nBila LIS dijalankan dengan \"php -S\", tambahkan " +
                   "PHP_CLI_SERVER_WORKERS=8 karena server itu melayani satu permintaan pada satu waktu.";
        }

        return "Gagal menghubungi LIS RSUD Hanau.\n" + t;
    }

    public RestTemplate getRest() throws NoSuchAlgorithmException, KeyManagementException {
        SSLContext sslContext = SSLContext.getInstance("SSL");
        javax.net.ssl.TrustManager[] trustManagers = {
            new X509TrustManager() {
                public X509Certificate[] getAcceptedIssuers() { return null; }
                public void checkServerTrusted(X509Certificate[] arg0, String arg1) throws CertificateException {}
                public void checkClientTrusted(X509Certificate[] arg0, String arg1) throws CertificateException {}
            }
        };
        sslContext.init(null, trustManagers, new SecureRandom());
        SSLSocketFactory sslFactory = new SSLSocketFactory(sslContext, SSLSocketFactory.ALLOW_ALL_HOSTNAME_VERIFIER);
        Scheme scheme = new Scheme("https", 443, sslFactory);
        HttpComponentsClientHttpRequestFactory factory = new HttpComponentsClientHttpRequestFactory();
        factory.getHttpClient().getConnectionManager().getSchemeRegistry().register(scheme);
        return new RestTemplate(factory);
    }
}
