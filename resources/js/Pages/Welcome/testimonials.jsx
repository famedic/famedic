import * as Headless from "@headlessui/react";
import { ArrowLongRightIcon } from "@heroicons/react/20/solid";
import { clsx } from "clsx";
import {
	motion,
	useMotionValueEvent,
	useScroll,
	useSpring,
} from "framer-motion";
import { useCallback, useLayoutEffect, useRef, useState } from "react";
import useMeasure from "react-use-measure";
import { Link } from "@/Components/Catalyst/link";
import { Text } from "@/Components/Catalyst/text";

const testimonials = [
	{
		question: "No encuentro el producto que estoy buscando",
		answer: "Contáctanos. Haremos todo los posible por conseguirlo.",
	},
	{
		question: "¿Qué métodos de pago se aceptan?",
		answer: "Tarjetas de crédito y débito. Al ser colaborador de Odessa, tambien puedes pagar con tu caja de ahorro.",
	},
	{
		question: "¿Hay algún tipo de promoción o descuento?",
		answer: "Toda los servicios de Famedic cuentan con un descuento importante ya aplicado.",
	},
	{
		question: "¿Dónde puedo solicitar mi factura?",
		answer: "Directamente desde la página de tu pedido. Adicionalmente puedes configurar las facturas automáticas.",
	},
	{
		question:
			"¿Se necesita un método de pago para la prueba de atención médica?",
		answer: "No. Puedes gozar de los beneficios gratis por 30 días.",
	},
];

function TestimonialCard({
	question,
	answer,
	children,
	bounds,
	scrollX,
	...props
}) {
	let ref = useRef(null);

	let computeOpacity = useCallback(() => {
		let element = ref.current;
		if (!element || bounds.width === 0) return 1;

		let rect = element.getBoundingClientRect();

		if (rect.left < bounds.left) {
			let diff = bounds.left - rect.left;
			let percent = diff / rect.width;
			return Math.max(0.5, 1 - percent);
		} else if (rect.right > bounds.right) {
			let diff = rect.right - bounds.right;
			let percent = diff / rect.width;
			return Math.max(0.5, 1 - percent);
		} else {
			return 1;
		}
	}, [ref, bounds.width, bounds.left, bounds.right]);

	let opacity = useSpring(computeOpacity(), {
		stiffness: 154,
		damping: 23,
	});

	useLayoutEffect(() => {
		opacity.set(computeOpacity());
	}, [computeOpacity, opacity]);

	useMotionValueEvent(scrollX, "change", () => {
		opacity.set(computeOpacity());
	});

	return (
		<motion.div
			ref={ref}
			style={{ opacity }}
			{...props}
			className="relative flex aspect-1 w-72 shrink-0 snap-start scroll-ml-[var(--scroll-padding)] flex-col justify-end overflow-hidden rounded-3xl sm:w-72"
		>
			<div
				aria-hidden="true"
				className="absolute inset-0 rounded-3xl bg-gradient-to-br from-slate-900 to-famedic-darker ring-1 ring-inset ring-gray-950/10"
			/>
			<figure className="relative p-10">
				<blockquote>
					<p className="relative font-poppins text-lg/7 text-famedic-lime">
						<span
							aria-hidden="true"
							className="absolute -translate-x-full"
						>
							“
						</span>
						{children}
						<span aria-hidden="true" className="absolute">
							”
						</span>
					</p>
				</blockquote>
				<figcaption className="mt-3 border-t border-white/20 pt-2">
					<p className="font-medium">
						<span className="bg-gradient-to-r from-white from-[10%] via-blue-300 to-famedic-light bg-clip-text text-transparent">
							{answer}
						</span>
					</p>
				</figcaption>
			</figure>
		</motion.div>
	);
}

function CallToAction() {
	return (
		<div>
			<Text>
				Disfruta de tus beneficios y empieza a usar Famedic hoy mismo.
			</Text>
			<div className="mt-2">
				<Link
					href={route("login")}
					className="inline-flex items-center gap-2 text-sm/6 font-medium text-famedic-light"
				>
					Ingresar
					<ArrowLongRightIcon className="size-5" />
				</Link>
			</div>
		</div>
	);
}

export function Testimonials() {
	let scrollRef = useRef(null);
	let { scrollX } = useScroll({ container: scrollRef });
	let [setReferenceWindowRef, bounds] = useMeasure();
	let [activeIndex, setActiveIndex] = useState(0);

	useMotionValueEvent(scrollX, "change", (x) => {
		setActiveIndex(
			Math.floor(x / scrollRef.current.children[0].clientWidth),
		);
	});

	function scrollTo(index) {
		let gap = 32;
		let width = scrollRef.current.children[0].offsetWidth;
		scrollRef.current.scrollTo({ left: (width + gap) * index });
	}

	return (
		<div className="overflow-hidden">
			<div className="px-6 lg:px-8">
				<div ref={setReferenceWindowRef}>
					<h2 className="font-poppins text-3xl/7 font-semibold text-famedic-light">
						Documentación
					</h2>
					<h2 className="text-pretty font-poppins text-4xl font-semibold tracking-tight text-famedic-darker sm:text-5xl dark:text-slate-300">
						Preguntas frecuentes
					</h2>
				</div>
			</div>
			<div
				ref={scrollRef}
				className={clsx([
					"mt-16 flex gap-8 px-[var(--scroll-padding)]",
					"[scrollbar-width:none] [&::-webkit-scrollbar]:hidden",
					"snap-x snap-mandatory overflow-x-auto overscroll-x-contain scroll-smooth",
					"[--scroll-padding:max(theme(spacing.6),calc((100vw-theme(maxWidth.2xl))/2))] lg:[--scroll-padding:max(theme(spacing.8),calc((100vw-theme(maxWidth.7xl))/2))]",
				])}
			>
				{testimonials.map(({ question, answer }, testimonialIndex) => (
					<TestimonialCard
						key={testimonialIndex}
						answer={answer}
						bounds={bounds}
						scrollX={scrollX}
						onClick={() => scrollTo(testimonialIndex)}
					>
						{question}
					</TestimonialCard>
				))}
				<div className="w-[42rem] shrink-0 sm:w-[54rem]" />
			</div>
			<div className="mt-16 px-6 lg:px-8">
				<div className="flex justify-between">
					<CallToAction />
					<div className="hidden sm:flex sm:gap-2">
						{testimonials.map(({ name }, testimonialIndex) => (
							<Headless.Button
								key={testimonialIndex}
								onClick={() => scrollTo(testimonialIndex)}
								data-active={
									activeIndex === testimonialIndex
										? true
										: undefined
								}
								aria-label={`Scroll to testimonial from ${name}`}
								className={clsx(
									"size-2.5 rounded-full border border-transparent bg-gray-300 transition",
									"data-[active]:bg-famedic-light data-[hover]:bg-famedic-light",
									"forced-colors:data-[active]:bg-[Highlight] forced-colors:data-[focus]:outline-offset-4",
								)}
							/>
						))}
					</div>
				</div>
			</div>
		</div>
	);
}
