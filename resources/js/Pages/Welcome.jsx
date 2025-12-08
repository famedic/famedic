import { Link } from "@inertiajs/react";
import useTrackingEvents from "@/Hooks/useTrackingEvents";
import { Testimonials } from "@/Pages/Welcome/testimonials";
import clsx from "clsx";
import FamedicLayout from "@/Layouts/FamedicLayout";

export default function Home() {
  useTrackingEvents();

  return (
    <FamedicLayout title="¡Bienvenido a Famedic!">
      <div className="space-y-16 lg:space-y-24">
        <Hero />
        <ServicesGrid />
        <MembershipBenefits />
        <HowItWorks />
        <ActionCards />
        <div id="preguntas" className="pt-8">
          <Testimonials />
        </div>
      </div>
    </FamedicLayout>
  );
}

function Hero() {
  return (
    <div className="relative overflow-hidden bg-gradient-to-br from-famedic-darker via-famedic-blue to-famedic-light">
      <div className="absolute inset-0 bg-grid-white/10 bg-[size:20px_20px]" />
      
      <div className="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pt-16 pb-24 lg:pt-24 lg:pb-32">
        <div className="lg:grid lg:grid-cols-2 lg:gap-16 items-center">
          <div className="text-center lg:text-left">
            <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/10 backdrop-blur-sm mb-6">
              <span className="text-xl">📈</span>
              <span className="text-sm font-medium text-white">Plataforma de Salud Digital</span>
            </div>
            
            <Title className="text-white">
              Tu salud,
              <span className="block text-famedic-lime mt-2">
                simplificada
              </span>
            </Title>
            
            <p className="mt-6 text-lg text-gray-200 max-w-2xl">
              Consultas médicas 24/7, medicamentos entregados en casa, estudios de laboratorio con descuentos exclusivos y todo en una sola plataforma.
            </p>
            
            <div className="mt-10 flex flex-col sm:flex-row gap-4 justify-center lg:justify-start">
              <Link
                href={route("register")}
                className="inline-flex items-center justify-center px-8 py-3 text-base font-semibold rounded-full bg-famedic-lime text-famedic-darker hover:bg-famedic-lime/90 transition-all transform hover:scale-105 shadow-lg hover:shadow-xl"
              >
                Comenzar ahora
                <span className="ml-2">📱</span>
              </Link>
              <Link
                href="#servicios"
                className="inline-flex items-center justify-center px-8 py-3 text-base font-semibold rounded-full border-2 border-white text-white hover:bg-white/10 transition-all"
              >
                Ver servicios
              </Link>
            </div>
            
            <div className="mt-12 grid grid-cols-3 gap-4 max-w-md">
              <div className="text-center">
                <div className="text-2xl font-bold text-white">500+</div>
                <div className="text-sm text-gray-300">Doctores</div>
              </div>
              <div className="text-center">
                <div className="text-2xl font-bold text-white">10k+</div>
                <div className="text-sm text-gray-300">Estudios mensuales</div>
              </div>
              <div className="text-center">
                <div className="text-2xl font-bold text-white">24/7</div>
                <div className="text-sm text-gray-300">Atención</div>
              </div>
            </div>
          </div>
          
          <div className="mt-12 lg:mt-0 relative">
            <div className="relative mx-auto max-w-md">
              <div className="absolute -inset-4 bg-gradient-to-r from-famedic-lime/20 to-famedic-blue/20 rounded-3xl blur-xl" />
              <div className="relative bg-gray-900 rounded-2xl shadow-2xl overflow-hidden border border-gray-800">
                <div className="bg-gray-800 px-4 py-3 flex items-center gap-2">
                  <div className="flex gap-1">
                    <div className="w-3 h-3 rounded-full bg-red-500" />
                    <div className="w-3 h-3 rounded-full bg-yellow-500" />
                    <div className="w-3 h-3 rounded-full bg-green-500" />
                  </div>
                  <div className="flex-1 text-center text-xs text-gray-400">famedic.com.mx</div>
                </div>
                
                <div className="p-6">
                  <div className="grid grid-cols-2 gap-4 mb-6">
                    <div className="bg-famedic-darker/50 rounded-xl p-4">
                      <span className="text-2xl block mb-2">🩺</span>
                      <div className="text-white text-sm font-medium">Consultar ahora</div>
                    </div>
                    <div className="bg-famedic-darker/50 rounded-xl p-4">
                      <span className="text-2xl block mb-2">💊</span>
                      <div className="text-white text-sm font-medium">Medicamentos</div>
                    </div>
                    <div className="bg-famedic-darker/50 rounded-xl p-4">
                      <span className="text-2xl block mb-2">🧪</span>
                      <div className="text-white text-sm font-medium">Laboratorios</div>
                    </div>
                    <div className="bg-famedic-darker/50 rounded-xl p-4">
                      <span className="text-2xl block mb-2">📅</span>
                      <div className="text-white text-sm font-medium">Agendar</div>
                    </div>
                  </div>
                  
                  <div className="space-y-3">
                    <div className="flex items-center justify-between text-sm">
                      <span className="text-gray-300">Hemograma</span>
                      <span className="text-famedic-lime font-semibold">-40%</span>
                    </div>
                    <div className="flex items-center justify-between text-sm">
                      <span className="text-gray-300">Perfil tiroideo</span>
                      <span className="text-famedic-lime font-semibold">-35%</span>
                    </div>
                    <div className="flex items-center justify-between text-sm">
                      <span className="text-gray-300">Consulta médica</span>
                      <span className="text-famedic-lime font-semibold">Gratis*</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

function ServicesGrid() {
  const services = [
    {
      icon: "🩺",
      title: "Médico 24/7",
      description: "Consultas virtuales inmediatas con médicos certificados. Primera consulta gratuita.",
      color: "from-blue-500 to-cyan-500",
      action: "Consultar ahora",
      href: "/consultas"
    },
    {
      icon: "💊",
      title: "Farmacia Digital",
      description: "Medicamentos entregados en tu hogar. Precios competitivos y entrega rápida.",
      color: "from-green-500 to-emerald-500",
      action: "Ver medicamentos",
      href: "/farmacia"
    },
    {
      icon: "🧪",
      title: "Laboratorios",
      description: "Estudios clínicos con hasta 50% de descuento. Agenda en línea fácilmente.",
      color: "from-purple-500 to-pink-500",
      action: "Agendar estudio",
      href: "/laboratorios"
    },
    {
      icon: "📋",
      title: "Historial Digital",
      description: "Tus resultados, recetas y consultas siempre accesibles en la nube.",
      color: "from-orange-500 to-red-500",
      action: "Ver historial",
      href: "/historial"
    }
  ];

  return (
    <div id="servicios" className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-16">
      <div className="text-center">
        <Subtitle className="text-famedic-light">Todo en una plataforma</Subtitle>
        <Title className="mt-2 text-white">
          Servicios de salud integrales
        </Title>
        <p className="mt-4 text-lg text-gray-600 max-w-3xl mx-auto">
          Desde consultas médicas hasta entrega de medicamentos y estudios de laboratorio. Todo integrado para tu comodidad.
        </p>
      </div>

      <div className="mt-12 grid gap-8 md:grid-cols-2 lg:grid-cols-4">
        {services.map((service, index) => (
          <div 
            key={index}
            className="group relative bg-white rounded-2xl p-6 shadow-lg hover:shadow-2xl transition-all duration-300 border border-gray-100 hover:border-famedic-light/30"
          >
            <div className={`absolute inset-0 bg-gradient-to-br ${service.color} opacity-0 group-hover:opacity-5 rounded-2xl transition-opacity`} />
            
            <div className={`inline-flex p-3 rounded-xl bg-gradient-to-br ${service.color} text-white mb-4`}>
              <span className="text-2xl">{service.icon}</span>
            </div>
            
            <h3 className="text-xl font-semibold text-gray-900 mb-3">
              {service.title}
            </h3>
            
            <p className="text-gray-600 mb-6">
              {service.description}
            </p>
            
            
          </div>
        ))}
      </div>
    </div>
  );
}

function MembershipBenefits() {
  const benefits = [
    {
      icon: "⏰",
      title: "Atención inmediata 24/7",
      description: "Consulta a un médico familiar cuando lo necesites, sin citas previas.",
      highlight: "Primer mes gratis"
    },
    {
      icon: "📉",
      title: "Descuentos exclusivos",
      description: "Hasta 50% de descuento en estudios de laboratorio y medicamentos.",
      highlight: "50% off"
    },
    {
      icon: "🏠",
      title: "Entrega a domicilio",
      description: "Recibe tus medicamentos y kits de laboratorio en la comodidad de tu hogar.",
      highlight: "Envío gratis"
    },
    {
      icon: "🛡️",
      title: "Seguro de salud",
      description: "Protección adicional en todas tus transacciones y servicios médicos.",
      highlight: "Protegido"
    }
  ];

  return (
    <div className="bg-gradient-to-b from-white to-gray-50 py-16">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="lg:flex lg:items-center lg:gap-16">
          <div className="lg:w-1/2">
            <Subtitle className="text-famedic-light">Tu cuenta Famedic</Subtitle>
            <Title className="mt-2">
              Beneficios premium
            </Title>
            
            <div className="mt-8 bg-white rounded-2xl p-8 shadow-xl border border-gray-200">
              <div className="flex items-center justify-between mb-6">
                <div>
                  <div className="text-2xl font-bold text-gray-900">$299<span className="text-lg text-gray-500">/anual</span></div>
                  <div className="text-sm text-gray-500">Membresía Atención medica</div>
                </div>
                <div className="bg-famedic-lime/10 text-famedic-darker px-4 py-2 rounded-full text-sm font-semibold">
                  1 mes gratis
                </div>
              </div>
              
              <ul className="space-y-4 mb-8">
                <li className="flex items-center gap-3">
                  <span className="text-xl">⏰</span>
                  <span>Consultas médicas ilimitadas</span>
                </li>
                <li className="flex items-center gap-3">
                  <span className="text-xl">🧪</span>
                  <span>Descuentos en laboratorios</span>
                </li>
                <li className="flex items-center gap-3">
                  <span className="text-xl">💊</span>
                  <span>Descuentos en farmacia</span>
                </li>
                <li className="flex items-center gap-3">
                  <span className="text-xl">🛡️</span>
                  <span>Seguro de protección</span>
                </li>
              </ul>
              
            </div>
          </div>
          
          <div className="mt-12 lg:mt-0 lg:w-1/2">
            <div className="grid grid-cols-2 gap-6">
              {benefits.map((benefit, index) => (
                <div 
                  key={index}
                  className="bg-white rounded-xl p-6 shadow-lg border border-gray-100 hover:shadow-xl transition-shadow"
                >
                  <div className="inline-flex p-3 rounded-lg bg-famedic-light/10 text-famedic-light mb-4">
                    <span className="text-2xl">{benefit.icon}</span>
                  </div>
                  
                  <div className="flex items-start justify-between">
                    <div>
                      <h4 className="font-semibold text-gray-900">{benefit.title}</h4>
                      
                    </div>
                    <div className="bg-famedic-lime/10 text-famedic-darker px-3 py-1 rounded-full text-xs font-semibold whitespace-nowrap ml-4">
                      {benefit.highlight}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

function HowItWorks() {
  const steps = [
    {
      step: "1",
      title: "Regístrate",
      description: "Crea tu cuenta en 2 minutos. Sin compromisos.",
      icon: "👤"
    },
    {
      step: "2",
      title: "Elige tu plan",
      description: "Selecciona membresía o paga por servicio individual.",
      icon: "📋"
    },
    {
      step: "3",
      title: "Accede a servicios",
      description: "Consulta, compra medicamentos o agenda estudios.",
      icon: "🩺"
    },
    {
      step: "4",
      title: "Recibe seguimiento",
      description: "Accede a resultados y recomendaciones personalizadas.",
      icon: "📊"
    }
  ];

  return (
    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-16">
      <div className="text-center">
        <Subtitle>Fácil y rápido</Subtitle>
        <Title className="mt-2 text-white">
          ¿Cómo funciona la plataforma?
        </Title>
      </div>

      <div className="mt-12 relative">
        <div className="hidden lg:block absolute top-12 left-0 right-0 h-0.5 bg-gradient-to-r from-famedic-light via-famedic-blue to-famedic-darker" />
        
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
          {steps.map((item, index) => (
            <div key={index} className="relative text-center">
              <div className="relative z-10 mx-auto w-20 h-20 bg-white rounded-full border-4 border-white shadow-lg flex items-center justify-center">
                <div className="text-3xl">{item.icon}</div>
              </div>
              
              <div className="mt-6">
                <div className="inline-block px-3 py-1 bg-famedic-light text-white text-sm font-semibold rounded-full">
                  Paso {item.step}
                </div>
                <h4 className="mt-4 text-xl font-semibold text-gray-900 text-white">
                  {item.title}
                </h4>
                <p className="mt-2 text-gray-600">
                  {item.description}
                </p>
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

function ActionCards() {
  return (
    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-12">
      <div className="grid md:grid-cols-3 gap-6">
        <div className="bg-gradient-to-br from-blue-50 to-cyan-50 rounded-2xl p-8 border border-blue-100">
          <span className="text-3xl block mb-4">🧪</span>
          <h3 className="text-2xl font-bold text-gray-900 mb-3">
            Estudios de laboratorio
          </h3>
          <p className="text-gray-600 mb-6">
            Agenda tus estudios con hasta 50% de descuento en los mejores laboratorios.
          </p>
          <div className="space-y-3 mb-6">
            <div className="flex items-center justify-between text-sm">
              <span>Hemograma</span>
              <span className="font-semibold text-famedic-darker">$107</span>
            </div>
            <div className="flex items-center justify-between text-sm">
              <span>Quimica sanguinea 6 elementos</span>
              <span className="font-semibold text-famedic-darker">$251</span>
            </div>
          </div>
          <Link
            href="laboratory-brand-selection"
            className="inline-flex items-center justify-center w-full bg-famedic-blue text-dark font-semibold py-3 px-6 rounded-xl hover:bg-famedic-blue/90 transition-colors"
          >
            Agendar estudio
            <span className="ml-2">📅</span>
          </Link>
        </div>

        <div className="bg-gradient-to-br from-green-50 to-emerald-50 rounded-2xl p-8 border border-green-100">
          <span className="text-3xl block mb-4">💊</span>
          <h3 className="text-2xl font-bold text-gray-900 mb-3">
            Farmacia en línea
          </h3>
          <p className="text-gray-600 mb-6">
            Envío gratis en pedidos mayores a $1,500. Entrega en 2-4 días.
          </p>
          <div className="flex items-center gap-4 mb-6">
            <div className="flex-1">
              <div className="text-sm text-gray-500">Medicamentos más buscados</div>
              <div className="text-sm font-medium text-gray-900">Paracetamol, Omeprazol, Ibuprofeno</div>
            </div>
          </div>
          <Link
            href="/online-pharmacy"
            className="inline-flex items-center justify-center w-full bg-green-600 text-white font-semibold py-3 px-6 rounded-xl hover:bg-green-700 transition-colors"
          >
            Ver catálogo
            <svg className="ml-2 w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
            </svg>
          </Link>
        </div>

        <div className="bg-gradient-to-br from-purple-50 to-pink-50 rounded-2xl p-8 border border-purple-100">
          <span className="text-3xl block mb-4">🩺</span>
          <h3 className="text-2xl font-bold text-gray-900 mb-3">
            Membresía Premium
          </h3>
          <p className="text-gray-600 mb-4">
            Todo incluido por solo $299/mes. Incluye:
          </p>
          <ul className="space-y-2 mb-6">
            <li className="flex items-center gap-2 text-sm">
              <div className="w-2 h-2 bg-purple-500 rounded-full" />
              Consultas médicas ilimitadas
            </li>            
            <li className="flex items-center gap-2 text-sm">
              <div className="w-2 h-2 bg-purple-500 rounded-full" />
              Resultados en línea
            </li>
          </ul>
          <Link
            href="/medical-attention"
            className="inline-flex items-center justify-center w-full bg-gradient-to-r from-purple-600 to-pink-600 text-white font-semibold py-3 px-6 rounded-xl hover:opacity-90 transition-opacity"
          >
            Probar gratis
            <span className="ml-2">⏰</span>
          </Link>
        </div>
      </div>
    </div>
  );
}

function Title({ children, level = 1, className }) {
  let Element = `h${level}`;

  return (
    <Element
      className={clsx(
        "font-poppins text-4xl font-bold tracking-tight text-gray-900 sm:text-5xl lg:text-6xl",
        className
      )}
    >
      {children}
    </Element>
  );
}

function Subtitle({ children, level = 2, className }) {
  let Element = `h${level}`;

  return (
    <Element
      className={clsx(
        "font-poppins text-lg font-semibold tracking-wide text-famedic-light uppercase",
        className
      )}
    >
      {children}
    </Element>
  );
}