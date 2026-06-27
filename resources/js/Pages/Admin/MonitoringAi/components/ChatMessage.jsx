import { useState } from "react";
import Markdown from "react-markdown";
import remarkGfm from "remark-gfm";
import clsx from "clsx";
import {
	ArrowPathIcon,
	ClipboardDocumentIcon,
	ExclamationTriangleIcon,
	SparklesIcon,
} from "@heroicons/react/24/outline";

function formatTime(timestamp) {
	if (!timestamp) return null;
	return new Date(timestamp).toLocaleTimeString("es-MX", {
		hour: "2-digit",
		minute: "2-digit",
	});
}

const markdownClasses = clsx(
	"prose prose-sm max-w-none dark:prose-invert",
	"prose-headings:mb-2 prose-headings:mt-3 prose-headings:font-semibold",
	"prose-p:my-1.5 prose-p:leading-relaxed",
	"prose-ul:my-2 prose-ol:my-2 prose-li:my-0.5",
	"prose-strong:font-semibold prose-strong:text-zinc-900 dark:prose-strong:text-zinc-100",
	"prose-a:text-famedic-light prose-a:no-underline hover:prose-a:underline",
	"prose-code:rounded prose-code:bg-zinc-100 prose-code:px-1 prose-code:py-0.5 prose-code:text-[0.85em] dark:prose-code:bg-zinc-800",
	"prose-pre:rounded-lg prose-pre:bg-zinc-100 dark:prose-pre:bg-zinc-800",
	"prose-hr:my-4",
);

export default function ChatMessage({ message, onRetry }) {
	const isUser = message.role === "user";
	const isError = message.isError;
	const [copied, setCopied] = useState(false);

	const handleCopy = async () => {
		try {
			await navigator.clipboard.writeText(message.content);
			setCopied(true);
			setTimeout(() => setCopied(false), 2000);
		} catch {
			// ignore
		}
	};

	return (
		<div
			className={clsx(
				"flex gap-3 px-4 py-3 sm:px-6",
				isUser ? "flex-row-reverse" : "flex-row",
			)}
		>
			<div
				className={clsx(
					"flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold",
					isUser
						? "bg-famedic-dark text-white"
						: isError
							? "bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300"
							: "bg-gradient-to-br from-famedic-dark to-famedic-light text-white",
				)}
			>
				{isUser ? (
					"Tú"
				) : isError ? (
					<ExclamationTriangleIcon className="size-4" />
				) : (
					<SparklesIcon className="size-4" />
				)}
			</div>

			<div
				className={clsx(
					"flex max-w-[85%] flex-col gap-1 sm:max-w-[75%]",
					isUser ? "items-end" : "items-start",
				)}
			>
				<div className="flex items-center gap-2 px-1">
					<span className="text-xs font-medium text-zinc-500">
						{isUser ? "Tú" : "Asistente IA"}
					</span>
					{message.createdAt && (
						<span className="text-xs text-zinc-400">
							{formatTime(message.createdAt)}
						</span>
					)}
				</div>

				<div
					className={clsx(
						"rounded-2xl px-4 py-3 shadow-sm",
						isUser
							? "rounded-br-md bg-famedic-dark text-white"
							: isError
								? "rounded-bl-md border border-red-200 bg-red-50 text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-200"
								: "rounded-bl-md border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900",
					)}
				>
					{isUser ? (
						<p className="whitespace-pre-wrap text-sm leading-relaxed">
							{message.content}
						</p>
					) : isError ? (
						<p className="text-sm leading-relaxed">{message.content}</p>
					) : (
						<div className={markdownClasses}>
							<Markdown remarkPlugins={[remarkGfm]}>
								{message.content}
							</Markdown>
						</div>
					)}
				</div>

				{!isUser && (
					<div className="flex items-center gap-1 px-1">
						{!isError && (
							<button
								type="button"
								onClick={handleCopy}
								className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
							>
								<ClipboardDocumentIcon className="size-3.5" />
								{copied ? "Copiado" : "Copiar"}
							</button>
						)}
						{isError && onRetry && (
							<button
								type="button"
								onClick={onRetry}
								className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs text-red-600 transition hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950/50"
							>
								<ArrowPathIcon className="size-3.5" />
								Reintentar
							</button>
						)}
					</div>
				)}
			</div>
		</div>
	);
}
