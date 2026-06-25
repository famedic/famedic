import { useCallback, useEffect, useRef, useState } from "react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Text } from "@/Components/Catalyst/text";
import axios from "axios";
import ChatHeader from "./components/ChatHeader";
import ChatComposer from "./components/ChatComposer";
import ChatMessage from "./components/ChatMessage";
import EmptyState from "./components/EmptyState";
import QuickPrompts from "./components/QuickPrompts";
import TypingIndicator from "./components/TypingIndicator";
import { QUICK_PROMPTS } from "./prompts";

const FRIENDLY_ERROR =
	"No pude consultar el asistente en este momento. Revisa la configuración o intenta de nuevo.";

function createMessage(role, content, extra = {}) {
	return {
		id: crypto.randomUUID(),
		role,
		content,
		createdAt: Date.now(),
		...extra,
	};
}

export default function MonitoringAiIndex({ isConfigured }) {
	const [question, setQuestion] = useState("");
	const [messages, setMessages] = useState([]);
	const [loading, setLoading] = useState(false);
	const scrollEndRef = useRef(null);

	const scrollToBottom = useCallback(() => {
		scrollEndRef.current?.scrollIntoView({ behavior: "smooth", block: "end" });
	}, []);

	useEffect(() => {
		scrollToBottom();
	}, [messages, loading, scrollToBottom]);

	const ask = useCallback(
		async (text) => {
			const trimmed = text.trim();
			if (!trimmed || loading || !isConfigured) return;

			setLoading(true);
			setMessages((prev) => [...prev, createMessage("user", trimmed)]);

			try {
				const { data } = await axios.post(route("admin.monitoring-ai.ask"), {
					question: trimmed,
				});

				setMessages((prev) => [
					...prev,
					createMessage("assistant", data.answer),
				]);
				setQuestion("");
			} catch {
				setMessages((prev) => [
					...prev,
					createMessage("assistant", FRIENDLY_ERROR, {
						isError: true,
						retryQuestion: trimmed,
					}),
				]);
			} finally {
				setLoading(false);
			}
		},
		[loading, isConfigured],
	);

	const handleRetry = useCallback(
		(retryQuestion) => {
			if (retryQuestion) {
				setMessages((prev) => {
					const last = prev[prev.length - 1];
					if (last?.isError) {
						return prev.slice(0, -1);
					}
					return prev;
				});
				ask(retryQuestion);
			}
		},
		[ask],
	);

	const handlePromptSelect = (promptQuestion) => {
		setQuestion(promptQuestion);
		ask(promptQuestion);
	};

	const hasConversation = messages.length > 0;

	return (
		<AdminLayout title="Asistente IA de Monitoreo">
			<div className="-mx-2 flex h-[calc(100dvh-7rem)] min-h-[32rem] flex-col sm:-mx-0 lg:h-[calc(100dvh-8rem)]">
				<div className="flex h-full flex-col overflow-hidden rounded-2xl border border-zinc-200/80 bg-zinc-50/50 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/30">
					<ChatHeader isConfigured={isConfigured} />

					{!isConfigured && (
						<div className="shrink-0 border-b border-amber-200/80 bg-amber-50 px-4 py-2.5 dark:border-amber-900/50 dark:bg-amber-950/30 sm:px-6">
							<Text className="text-sm text-amber-800 dark:text-amber-200">
								Agrega{" "}
								<code className="rounded bg-amber-100 px-1 text-xs dark:bg-amber-900/50">
									OPENAI_API_KEY
								</code>{" "}
								en el entorno del servidor para habilitar el asistente.
							</Text>
						</div>
					)}

					<div className="flex flex-1 flex-col overflow-y-auto">
						{!hasConversation && !loading ? (
							<>
								<EmptyState
									onSelect={handlePromptSelect}
									disabled={!isConfigured || loading}
								/>
								<div className="px-4 pb-4 sm:px-6">
									<QuickPrompts
										prompts={QUICK_PROMPTS}
										onSelect={handlePromptSelect}
										disabled={!isConfigured || loading}
									/>
								</div>
							</>
						) : (
							<div className="flex flex-col py-2">
								{messages.map((message) => (
									<ChatMessage
										key={message.id}
										message={message}
										onRetry={
											message.isError && message.retryQuestion
												? () => handleRetry(message.retryQuestion)
												: undefined
										}
									/>
								))}
								{loading && <TypingIndicator />}
								<div ref={scrollEndRef} className="h-px shrink-0" />
							</div>
						)}
					</div>

					{hasConversation && (
						<QuickPrompts
							prompts={QUICK_PROMPTS}
							onSelect={handlePromptSelect}
							disabled={!isConfigured || loading}
							compact
						/>
					)}

					<ChatComposer
						value={question}
						onChange={setQuestion}
						onSubmit={() => ask(question)}
						loading={loading}
						disabled={!isConfigured}
					/>
				</div>
			</div>
		</AdminLayout>
	);
}
