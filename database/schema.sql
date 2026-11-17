--
-- PostgreSQL database dump
--


-- Dumped from database version 18.6 (Ubuntu 18.6-0ubuntu0.26.04.1)
-- Dumped by pg_dump version 18.6 (Ubuntu 18.6-0ubuntu0.26.04.1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: pgcrypto; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;


--
-- Name: EXTENSION pgcrypto; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION pgcrypto IS 'cryptographic functions';


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: sol_solicitudes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sol_solicitudes (
    id integer NOT NULL,
    entidad_id integer NOT NULL,
    uid character varying(40) NOT NULL,
    fila integer,
    item integer,
    cod_modular character varying(30),
    cod_cargo character varying(30),
    dni character varying(30),
    nombres character varying(150),
    apellidos character varying(150),
    tipo_producto character varying(60),
    grupo_descuento character varying(60),
    entidad_texto character varying(150),
    entidad_compra character varying(150),
    periodo character varying(20),
    cuota numeric(12,2),
    condicion character varying(20),
    hash character(32),
    estado character varying(20) DEFAULT 'PENDIENTE'::character varying NOT NULL,
    observaciones text,
    en_hoja boolean DEFAULT true NOT NULL,
    disponible numeric(12,2),
    margen numeric(12,2),
    evaluado_por character varying(100),
    evaluado_en timestamp with time zone,
    comentario text,
    hoja_escrito text,
    recibido timestamp with time zone DEFAULT now() NOT NULL,
    actualizado timestamp with time zone DEFAULT now() NOT NULL,
    duplicado_de integer,
    registrado_por character varying(150),
    registrado_fecha character varying(150),
    auto_regla boolean DEFAULT false NOT NULL,
    otic_recepcion character varying(200),
    otic_visto boolean DEFAULT true NOT NULL,
    tipo_trabajador character varying(15)
);


--
-- Name: avisos_solicitudes; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.avisos_solicitudes AS
 SELECT id,
    estado,
    recibido,
    evaluado_en
   FROM public.sol_solicitudes;


--
-- Name: descuentos_catalogo; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.descuentos_catalogo (
    id integer NOT NULL,
    nombre character varying(100) NOT NULL,
    tipo character varying(20) DEFAULT 'monto'::character varying NOT NULL,
    valor_sugerido numeric(10,4) DEFAULT 0 NOT NULL,
    orden integer DEFAULT 0 NOT NULL,
    activo boolean DEFAULT true NOT NULL,
    creado_en timestamp without time zone DEFAULT now() NOT NULL,
    grupo_exclusivo character varying(50),
    veces_usado integer DEFAULT 0 NOT NULL,
    CONSTRAINT descuentos_catalogo_tipo_check CHECK (((tipo)::text = ANY (ARRAY[('monto'::character varying)::text, ('porcentaje'::character varying)::text])))
);


--
-- Name: descuentos_catalogo_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.descuentos_catalogo_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: descuentos_catalogo_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.descuentos_catalogo_id_seq OWNED BY public.descuentos_catalogo.id;


--
-- Name: entidades_catalogo; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.entidades_catalogo (
    id integer NOT NULL,
    nombre character varying(100) NOT NULL,
    tipo character varying(20) DEFAULT 'cooperativa'::character varying NOT NULL,
    orden integer DEFAULT 0 NOT NULL,
    activo boolean DEFAULT true NOT NULL,
    creado_en timestamp without time zone DEFAULT now() NOT NULL,
    veces_usado integer DEFAULT 0 NOT NULL,
    CONSTRAINT entidades_catalogo_tipo_check CHECK (((tipo)::text = ANY (ARRAY[('banco'::character varying)::text, ('cooperativa'::character varying)::text, ('caja'::character varying)::text, ('otro'::character varying)::text])))
);


--
-- Name: entidades_catalogo_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.entidades_catalogo_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: entidades_catalogo_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.entidades_catalogo_id_seq OWNED BY public.entidades_catalogo.id;


--
-- Name: evaluacion_creditos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.evaluacion_creditos (
    id integer NOT NULL,
    evaluacion_id integer NOT NULL,
    entidad_nombre character varying(100) NOT NULL,
    entidad_tipo character varying(20) DEFAULT 'cooperativa'::character varying NOT NULL,
    monto_solicitado numeric(12,2) DEFAULT 0 NOT NULL
);


--
-- Name: evaluacion_creditos_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.evaluacion_creditos_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: evaluacion_creditos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.evaluacion_creditos_id_seq OWNED BY public.evaluacion_creditos.id;


--
-- Name: evaluacion_descuentos; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.evaluacion_descuentos (
    id integer NOT NULL,
    evaluacion_id integer NOT NULL,
    nombre character varying(100) NOT NULL,
    monto numeric(12,2) DEFAULT 0 NOT NULL
);


--
-- Name: evaluacion_descuentos_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.evaluacion_descuentos_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: evaluacion_descuentos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.evaluacion_descuentos_id_seq OWNED BY public.evaluacion_descuentos.id;


--
-- Name: evaluaciones; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.evaluaciones (
    id integer NOT NULL,
    usuario_id integer,
    trabajador_nombre character varying(150) NOT NULL,
    trabajador_dni character varying(20),
    trabajador_dependencia character varying(150),
    sueldo_bruto numeric(12,2) NOT NULL,
    total_descuentos numeric(12,2) DEFAULT 0 NOT NULL,
    neto_total numeric(12,2) NOT NULL,
    neto_50 numeric(12,2) NOT NULL,
    total_solicitado numeric(12,2) DEFAULT 0 NOT NULL,
    capacidad_disponible numeric(12,2) NOT NULL,
    excede_limite boolean DEFAULT false NOT NULL,
    observaciones text,
    creado_en timestamp without time zone DEFAULT now() NOT NULL,
    trabajador_id integer,
    tipo_personal character varying(20) DEFAULT 'administrativo'::character varying NOT NULL,
    sueldo_bruto_base numeric(12,2) DEFAULT 0 NOT NULL,
    sueldo_adicional_concepto character varying(150),
    sueldo_adicional_monto numeric(12,2) DEFAULT 0 NOT NULL,
    CONSTRAINT evaluaciones_tipo_personal_check CHECK (((tipo_personal)::text = ANY (ARRAY[('administrativo'::character varying)::text, ('docente'::character varying)::text])))
);


--
-- Name: evaluaciones_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.evaluaciones_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: evaluaciones_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.evaluaciones_id_seq OWNED BY public.evaluaciones.id;


--
-- Name: sol_ajustes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sol_ajustes (
    clave character varying(60) NOT NULL,
    valor text,
    actualizado timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: sol_entidades; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sol_entidades (
    id integer NOT NULL,
    nombre character varying(150) NOT NULL,
    grupo character varying(60),
    spreadsheet_id character varying(120) NOT NULL,
    hoja character varying(60) DEFAULT 'SOLICITUDES'::character varying NOT NULL,
    titulo_hoja character varying(200),
    activa boolean DEFAULT true NOT NULL,
    ultima_sync timestamp with time zone,
    ultimo_error text,
    creado timestamp with time zone DEFAULT now() NOT NULL,
    codigo character varying(10)
);


--
-- Name: sol_entidades_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sol_entidades_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sol_entidades_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sol_entidades_id_seq OWNED BY public.sol_entidades.id;


--
-- Name: sol_evaluaciones; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sol_evaluaciones (
    id integer NOT NULL,
    solicitud_id integer NOT NULL,
    remuneracion numeric(12,2) NOT NULL,
    descuentos_ley numeric(12,2) DEFAULT 0 NOT NULL,
    terceros text,
    total_terceros numeric(12,2) DEFAULT 0 NOT NULL,
    cuota_liberada numeric(12,2) DEFAULT 0 NOT NULL,
    aprobadas_sistema numeric(12,2) DEFAULT 0 NOT NULL,
    porcentaje numeric(5,2) NOT NULL,
    base numeric(12,2) NOT NULL,
    tope numeric(12,2) NOT NULL,
    disponible numeric(12,2) NOT NULL,
    cuota numeric(12,2) NOT NULL,
    resultado character varying(15) NOT NULL,
    comentario text,
    usuario character varying(100),
    creado timestamp with time zone DEFAULT now() NOT NULL,
    ley text,
    calc_evaluacion_id integer,
    resultado_calculo character varying(15),
    modo character varying(15) DEFAULT 'ADMINISTRATIVO'::character varying NOT NULL,
    disponible_libre numeric(12,2)
);


--
-- Name: sol_evaluaciones_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sol_evaluaciones_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sol_evaluaciones_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sol_evaluaciones_id_seq OWNED BY public.sol_evaluaciones.id;


--
-- Name: sol_historial; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sol_historial (
    id integer NOT NULL,
    solicitud_id integer NOT NULL,
    accion character varying(40) NOT NULL,
    detalle text,
    usuario character varying(100),
    creado timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: sol_historial_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sol_historial_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sol_historial_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sol_historial_id_seq OWNED BY public.sol_historial.id;


--
-- Name: sol_padron; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sol_padron (
    dni character varying(8) NOT NULL,
    apellido_paterno character varying(80),
    apellido_materno character varying(80),
    nombres character varying(120),
    cod_modular character varying(12),
    fuente character varying(40),
    actualizado timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: sol_padron_cargas; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sol_padron_cargas (
    id integer NOT NULL,
    fecha timestamp with time zone DEFAULT now() NOT NULL,
    usuario character varying(80),
    archivos character varying(500),
    personas integer,
    nuevos integer,
    reemplazo boolean DEFAULT false NOT NULL
);


--
-- Name: sol_padron_cargas_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sol_padron_cargas_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sol_padron_cargas_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sol_padron_cargas_id_seq OWNED BY public.sol_padron_cargas.id;


--
-- Name: sol_solicitudes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sol_solicitudes_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sol_solicitudes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sol_solicitudes_id_seq OWNED BY public.sol_solicitudes.id;


--
-- Name: sol_sync_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sol_sync_log (
    id integer NOT NULL,
    entidad_id integer,
    inicio timestamp with time zone DEFAULT now() NOT NULL,
    fin timestamp with time zone,
    leidas integer DEFAULT 0,
    nuevas integer DEFAULT 0,
    actualizadas integer DEFAULT 0,
    retiradas integer DEFAULT 0,
    escritas integer DEFAULT 0,
    error text
);


--
-- Name: sol_sync_log_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sol_sync_log_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sol_sync_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sol_sync_log_id_seq OWNED BY public.sol_sync_log.id;


--
-- Name: trabajadores; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.trabajadores (
    id integer NOT NULL,
    nombres character varying(150) NOT NULL,
    dni character varying(20),
    dependencia character varying(150),
    tipo_personal character varying(20) DEFAULT 'administrativo'::character varying NOT NULL,
    sueldo_bruto_referencia numeric(12,2),
    creado_en timestamp without time zone DEFAULT now() NOT NULL,
    actualizado_en timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT trabajadores_tipo_personal_check CHECK (((tipo_personal)::text = ANY (ARRAY[('administrativo'::character varying)::text, ('docente'::character varying)::text])))
);


--
-- Name: trabajadores_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.trabajadores_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: trabajadores_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.trabajadores_id_seq OWNED BY public.trabajadores.id;


--
-- Name: usuarios; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.usuarios (
    id integer NOT NULL,
    nombre_completo character varying(150) NOT NULL,
    usuario character varying(50) NOT NULL,
    password_hash character varying(255) NOT NULL,
    rol character varying(20) DEFAULT 'operador'::character varying NOT NULL,
    activo boolean DEFAULT true NOT NULL,
    creado_en timestamp without time zone DEFAULT now() NOT NULL,
    correo character varying(150),
    celular character varying(20),
    direccion character varying(255),
    cargo character varying(100),
    area character varying(150),
    CONSTRAINT usuarios_rol_check CHECK (((rol)::text = ANY (ARRAY[('admin'::character varying)::text, ('operador'::character varying)::text])))
);


--
-- Name: usuarios_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.usuarios_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: usuarios_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.usuarios_id_seq OWNED BY public.usuarios.id;


--
-- Name: descuentos_catalogo id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.descuentos_catalogo ALTER COLUMN id SET DEFAULT nextval('public.descuentos_catalogo_id_seq'::regclass);


--
-- Name: entidades_catalogo id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entidades_catalogo ALTER COLUMN id SET DEFAULT nextval('public.entidades_catalogo_id_seq'::regclass);


--
-- Name: evaluacion_creditos id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evaluacion_creditos ALTER COLUMN id SET DEFAULT nextval('public.evaluacion_creditos_id_seq'::regclass);


--
-- Name: evaluacion_descuentos id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evaluacion_descuentos ALTER COLUMN id SET DEFAULT nextval('public.evaluacion_descuentos_id_seq'::regclass);


--
-- Name: evaluaciones id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evaluaciones ALTER COLUMN id SET DEFAULT nextval('public.evaluaciones_id_seq'::regclass);


--
-- Name: sol_entidades id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sol_entidades ALTER COLUMN id SET DEFAULT nextval('public.sol_entidades_id_seq'::regclass);


--
-- Name: sol_evaluaciones id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sol_evaluaciones ALTER COLUMN id SET DEFAULT nextval('public.sol_evaluaciones_id_seq'::regclass);


--
-- Name: sol_historial id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sol_historial ALTER COLUMN id SET DEFAULT nextval('public.sol_historial_id_seq'::regclass);


--
-- Name: sol_padron_cargas id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sol_padron_cargas ALTER COLUMN id SET DEFAULT nextval('public.sol_padron_cargas_id_seq'::regclass);


--
-- Name: sol_solicitudes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sol_solicitudes ALTER COLUMN id SET DEFAULT nextval('public.sol_solicitudes_id_seq'::regclass);


--
-- Name: sol_sync_log id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sol_sync_log ALTER COLUMN id SET DEFAULT nextval('public.sol_sync_log_id_seq'::regclass);


--
-- Name: trabajadores id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trabajadores ALTER COLUMN id SET DEFAULT nextval('public.trabajadores_id_seq'::regclass);


--
-- Name: usuarios id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuarios ALTER COLUMN id SET DEFAULT nextval('public.usuarios_id_seq'::regclass);


--
-- Name: descuentos_catalogo descuentos_catalogo_nombre_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.descuentos_catalogo
    ADD CONSTRAINT descuentos_catalogo_nombre_key UNIQUE (nombre);


--
-- Name: descuentos_catalogo descuentos_catalogo_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.descuentos_catalogo
    ADD CONSTRAINT descuentos_catalogo_pkey PRIMARY KEY (id);


--
-- Name: entidades_catalogo entidades_catalogo_nombre_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entidades_catalogo
    ADD CONSTRAINT entidades_catalogo_nombre_key UNIQUE (nombre);


--
-- Name: entidades_catalogo entidades_catalogo_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.entidades_catalogo
    ADD CONSTRAINT entidades_catalogo_pkey PRIMARY KEY (id);


--
-- Name: evaluacion_creditos evaluacion_creditos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evaluacion_creditos
    ADD CONSTRAINT evaluacion_creditos_pkey PRIMARY KEY (id);


--
-- Name: evaluacion_descuentos evaluacion_descuentos_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evaluacion_descuentos
    ADD CONSTRAINT evaluacion_descuentos_pkey PRIMARY KEY (id);


--
-- Name: evaluaciones evaluaciones_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evaluaciones
    ADD CONSTRAINT evaluaciones_pkey PRIMARY KEY (id);


--
-- Name: sol_ajustes sol_ajustes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sol_ajustes
    ADD CONSTRAINT sol_ajustes_pkey PRIMARY KEY (clave);


--
-- Name: sol_entidades sol_entidades_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sol_entidades
    ADD CONSTRAINT sol_entidades_pkey PRIMARY KEY (id);


--
-- Name: sol_entidades sol_entidades_spreadsheet_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sol_entidades
    ADD CONSTRAINT sol_entidades_spreadsheet_id_key UNIQUE (spreadsheet_id);


--
-- Name: sol_evaluaciones sol_evaluaciones_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sol_evaluaciones
    ADD CONSTRAINT sol_evaluaciones_pkey PRIMARY KEY (id);


--
-- Name: sol_historial sol_historial_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sol_historial
    ADD CONSTRAINT sol_historial_pkey PRIMARY KEY (id);


--
-- Name: sol_padron_cargas sol_padron_cargas_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sol_padron_cargas
    ADD CONSTRAINT sol_padron_cargas_pkey PRIMARY KEY (id);


--
-- Name: sol_padron sol_padron_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sol_padron
    ADD CONSTRAINT sol_padron_pkey PRIMARY KEY (dni);


--
-- Name: sol_solicitudes sol_solicitudes_entidad_id_uid_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sol_solicitudes
    ADD CONSTRAINT sol_solicitudes_entidad_id_uid_key UNIQUE (entidad_id, uid);


--
-- Name: sol_solicitudes sol_solicitudes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sol_solicitudes
    ADD CONSTRAINT sol_solicitudes_pkey PRIMARY KEY (id);


--
-- Name: sol_sync_log sol_sync_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sol_sync_log
    ADD CONSTRAINT sol_sync_log_pkey PRIMARY KEY (id);


--
-- Name: trabajadores trabajadores_dni_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trabajadores
    ADD CONSTRAINT trabajadores_dni_key UNIQUE (dni);


--
-- Name: trabajadores trabajadores_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.trabajadores
    ADD CONSTRAINT trabajadores_pkey PRIMARY KEY (id);


--
-- Name: usuarios usuarios_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT usuarios_pkey PRIMARY KEY (id);


--
-- Name: usuarios usuarios_usuario_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT usuarios_usuario_key UNIQUE (usuario);


--
-- Name: idx_eval_creditos_eval; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_eval_creditos_eval ON public.evaluacion_creditos USING btree (evaluacion_id);


--
-- Name: idx_eval_descuentos_eval; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_eval_descuentos_eval ON public.evaluacion_descuentos USING btree (evaluacion_id);


--
-- Name: idx_evaluaciones_fecha; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_evaluaciones_fecha ON public.evaluaciones USING btree (creado_en DESC);


--
-- Name: idx_evaluaciones_trabajador; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_evaluaciones_trabajador ON public.evaluaciones USING btree (trabajador_nombre);


--
-- Name: idx_evaluaciones_trabajador_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_evaluaciones_trabajador_id ON public.evaluaciones USING btree (trabajador_id);


--
-- Name: idx_trabajadores_dni; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_trabajadores_dni ON public.trabajadores USING btree (dni);


--
-- Name: idx_trabajadores_nombres; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_trabajadores_nombres ON public.trabajadores USING btree (nombres);


--
-- Name: ix_eval_sol; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_eval_sol ON public.sol_evaluaciones USING btree (solicitud_id);


--
-- Name: ix_hist_sol; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_hist_sol ON public.sol_historial USING btree (solicitud_id);


--
-- Name: ix_sol_dni; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_sol_dni ON public.sol_solicitudes USING btree (dni);


--
-- Name: ix_sol_entidad_cod; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_sol_entidad_cod ON public.sol_entidades USING btree (codigo);


--
-- Name: ix_sol_estado; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_sol_estado ON public.sol_solicitudes USING btree (estado);


--
-- Name: ix_sol_periodo; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_sol_periodo ON public.sol_solicitudes USING btree (periodo);


--
-- Name: ix_sol_recibido; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ix_sol_recibido ON public.sol_solicitudes USING btree (recibido DESC);


--
-- Name: evaluacion_creditos evaluacion_creditos_evaluacion_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evaluacion_creditos
    ADD CONSTRAINT evaluacion_creditos_evaluacion_id_fkey FOREIGN KEY (evaluacion_id) REFERENCES public.evaluaciones(id) ON DELETE CASCADE;


--
-- Name: evaluacion_descuentos evaluacion_descuentos_evaluacion_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evaluacion_descuentos
    ADD CONSTRAINT evaluacion_descuentos_evaluacion_id_fkey FOREIGN KEY (evaluacion_id) REFERENCES public.evaluaciones(id) ON DELETE CASCADE;


--
-- Name: evaluaciones evaluaciones_trabajador_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evaluaciones
    ADD CONSTRAINT evaluaciones_trabajador_id_fkey FOREIGN KEY (trabajador_id) REFERENCES public.trabajadores(id);


--
-- Name: evaluaciones evaluaciones_usuario_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.evaluaciones
    ADD CONSTRAINT evaluaciones_usuario_id_fkey FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id);


--
-- Name: sol_evaluaciones sol_evaluaciones_solicitud_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sol_evaluaciones
    ADD CONSTRAINT sol_evaluaciones_solicitud_id_fkey FOREIGN KEY (solicitud_id) REFERENCES public.sol_solicitudes(id) ON DELETE CASCADE;


--
-- Name: sol_historial sol_historial_solicitud_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sol_historial
    ADD CONSTRAINT sol_historial_solicitud_id_fkey FOREIGN KEY (solicitud_id) REFERENCES public.sol_solicitudes(id) ON DELETE CASCADE;


--
-- Name: sol_solicitudes sol_solicitudes_entidad_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sol_solicitudes
    ADD CONSTRAINT sol_solicitudes_entidad_id_fkey FOREIGN KEY (entidad_id) REFERENCES public.sol_entidades(id) ON DELETE CASCADE;


--
-- Name: sol_sync_log sol_sync_log_entidad_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sol_sync_log
    ADD CONSTRAINT sol_sync_log_entidad_id_fkey FOREIGN KEY (entidad_id) REFERENCES public.sol_entidades(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--


